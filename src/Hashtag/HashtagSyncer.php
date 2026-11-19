<?php

namespace Ernestdefoe\Hashtags\Hashtag;

use Ernestdefoe\Hashtags\Formatter\ConfigureHashtags;
use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use s9e\TextFormatter\Utils;

/**
 * Reconciles posts' hashtag rows against the HASHTAG tags in their content.
 *
 * Called from the queued job (post saved, hidden or restored) and, a chunk of
 * posts at a time, from `hashtags:reindex`. The operation is a full
 * diff-and-replace for each post, so running it twice is a no-op — which
 * matters because queue retries are not exactly-once.
 *
 * It keeps no counts and deletes no hashtags. What a hashtag counts depends
 * on who is looking, so the API counts the posts the reader can see
 * (VisibleUsage), and a hashtag no visible post uses is neither listed nor
 * found. Its row stays, to be reused the next time someone writes it:
 * deleting it here raced a concurrent sync inserting a row for the same
 * hashtag, and the loser failed its job. `hashtags:prune` (daily) deletes
 * unused hashtags first written more than 30 days ago.
 */
class HashtagSyncer
{
    /**
     * SQLSTATEs a foreign key violation arrives as: 23000 on MySQL and SQLite
     * (which use it for every integrity violation), 23503 on PostgreSQL.
     * Retrying the sync after any of them is harmless: it is idempotent.
     */
    public const INTEGRITY_VIOLATIONS = ['23000', '23503'];

    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function sync(Post $post): void
    {
        $this->syncMany([$post]);
    }

    /**
     * One transaction and a fixed number of queries for any number of posts.
     *
     * @param iterable<Post> $posts
     */
    public function syncMany(iterable $posts): void
    {
        $names = $discussions = [];

        foreach ($posts as $post) {
            // Only comment posts carry user-authored content. Event posts
            // (discussion renamed, tags changed, cross-reference backlinks…)
            // have no XML to read and must never contribute hashtags.
            if ($post instanceof CommentPost) {
                $names[(int) $post->id] = $this->visibleNames($post);
                $discussions[(int) $post->id] = (int) $post->discussion_id;
            }
        }

        if (! $names) {
            return;
        }

        // Two attempts: Laravel retries a deadlock, which MySQL can raise when
        // this meets hashtags:prune's DELETE.
        $write = fn () => $this->db->transaction(fn () => $this->write($names, $discussions), 2);

        try {
            $write();
        } catch (QueryException $e) {
            /**
             * `hashtags:prune` deleted one of these hashtags between our
             * lookup and our insert (it only deletes unused ones, and the
             * foreign key refuses to delete a used one, so this is its
             * window). The insert's foreign key caught it; running again
             * recreates the hashtag. Any other error is not ours to swallow.
             */
            if (! in_array((string) $e->getCode(), self::INTEGRITY_VIOLATIONS, true)) {
                throw $e;
            }

            $write();
        }
    }

    /**
     * @param array<int, array<string, string>> $names post id => [key => display name]
     * @param array<int, int> $discussions post id => discussion id
     */
    protected function write(array $names, array $discussions): void
    {
        // `+` keeps the first spelling seen for a key, like namesFromXml().
        $ids = $this->resolveIds(array_reduce($names, fn (array $all, array $post) => $all + $post, []));

        $this->db->table('post_hashtag')->whereIn('post_id', array_keys($names))->delete();

        $rows = [];

        foreach ($names as $postId => $byKey) {
            foreach (array_keys($byKey) as $key) {
                $rows[] = ['post_id' => $postId, 'hashtag_id' => $ids[$key], 'discussion_id' => $discussions[$postId]];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table('post_hashtag')->insert($chunk);
        }
    }

    /**
     * The hashtags a post should currently count for.
     *
     * A hidden post contributes nothing — but its rows are removed rather than
     * flagged, so unhiding has to re-sync (see SyncHashtagsOnPostSave, which
     * listens for Restored as well as Posted/Revised).
     */
    protected function visibleNames(CommentPost $post): array
    {
        if ($post->hidden_at !== null) {
            return [];
        }

        /**
         * parsed_content, NOT content.
         *
         * HasFormattedContent's accessor makes `$post->content` UNPARSE on
         * read — it hands back the source text the author typed. The XML that
         * actually holds the HASHTAG elements is `$post->parsed_content`.
         * Reading `content` here finds no tags and no error: the sync silently
         * does nothing, forever.
         */
        $xml = $post->parsed_content;

        if ($xml === null || $xml === '') {
            return [];
        }

        return self::namesFromXml($xml);
    }

    /**
     * Pull the hashtags out of a post's parsed XML, folded and de-duplicated.
     *
     * Static and string-in/array-out so the extraction rules can be tested
     * without a database or a booted Flarum — this is the logic most likely to
     * drift, and it failed silently the first time it was wrong.
     *
     * @return array<string, string> lookup key => display name
     */
    public static function namesFromXml(?string $xml): array
    {
        if ($xml === null || $xml === '') {
            return [];
        }

        $names = Utils::getAttributeValues($xml, ConfigureHashtags::TAG, 'name');

        /**
         * Fold to the lookup key and de-duplicate. A post writing
         * "#gameday and #GameDay" has used ONE hashtag, so the pivot must not
         * receive two rows for it — the (post_id, hashtag_id) primary key
         * would reject the insert outright.
         *
         * The first spelling seen wins as the display casing for a hashtag
         * that does not exist yet.
         */
        $byKey = [];

        foreach ($names as $name) {
            $name = mb_substr(trim($name), 0, ConfigureHashtags::MAX_LENGTH, 'UTF-8');

            if ($name === '') {
                continue;
            }

            $byKey[Hashtag::key($name)] ??= $name;
        }

        return $byKey;
    }

    /**
     * Map [key => display name] to [key => hashtag id], creating rows for
     * hashtags nobody has used before.
     *
     * @param array<string, string> $names
     * @return array<string, int>
     */
    protected function resolveIds(array $names): array
    {
        if (! $names) {
            return [];
        }

        $keys = array_keys($names);

        $existing = Hashtag::query()->whereIn('name_key', $keys)->pluck('id', 'name_key')->all();

        $missing = array_diff($keys, array_keys($existing));

        if ($missing) {
            /**
             * insertOrIgnore, not insert: two people can post the same brand-new
             * hashtag in the same second, and the loser of that race would
             * otherwise take a unique-constraint violation all the way up into
             * a failed job. Ignoring the collision and re-reading below gives
             * both posts the same id.
             */
            $this->db->table('hashtags')->insertOrIgnore(
                // created_at comes from the column default.
                array_map(fn (string $key) => [
                    'name' => $names[$key],
                    'name_key' => $key,
                ], array_values($missing))
            );

            $existing = Hashtag::query()->whereIn('name_key', $keys)->pluck('id', 'name_key')->all();
        }

        return array_map('intval', $existing);
    }
}
