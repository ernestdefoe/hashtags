<?php

namespace Ernestdefoe\Hashtags\Hashtag;

use Ernestdefoe\Hashtags\Formatter\ConfigureHashtags;
use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use s9e\TextFormatter\Utils;

/**
 * Reconciles one post's hashtag rows against the HASHTAG tags in its content.
 *
 * Called from the queued job on post save, and directly on hide/restore. The
 * operation is a full diff-and-replace for a single post, so running it twice
 * is a no-op — which matters because queue retries are not exactly-once.
 */
class HashtagSyncer
{
    public function __construct(protected ConnectionInterface $db) {}

    public function sync(Post $post): void
    {
        // Only comment posts carry user-authored content. Event posts
        // (discussion renamed, tags changed, cross-reference backlinks…)
        // have no XML to read and must never contribute hashtags.
        if (! $post instanceof CommentPost) {
            return;
        }

        $names = $this->visibleNames($post);

        $this->db->transaction(function () use ($post, $names) {
            $ids = $this->resolveIds($names);

            // Which hashtags did this post point at BEFORE this sync? Union
            // with the new set gives every hashtag whose counters can have
            // changed — including ones the edit removed, which is the case a
            // naive "recount what's in the post now" would silently miss.
            $previous = $this->db->table('post_hashtag')
                ->where('post_id', $post->id)
                ->pluck('hashtag_id')
                ->all();

            $this->db->table('post_hashtag')->where('post_id', $post->id)->delete();

            if ($ids) {
                $this->db->table('post_hashtag')->insert(
                    array_map(fn (int $id) => [
                        'post_id' => (int) $post->id,
                        'hashtag_id' => $id,
                        'discussion_id' => (int) $post->discussion_id,
                    ], array_values($ids))
                );
            }

            $this->recount(array_unique(array_merge($previous, array_values($ids))));
        });
    }

    /**
     * Recount after a post has already been deleted.
     *
     * The pivot rows are gone by now (FK cascade), but the Deleted event still
     * carries the post model in memory, so its content tells us exactly which
     * hashtags need their totals corrected. Without this, deleting the only
     * post using #foo would leave the hashtag sitting in the autocomplete with
     * a stale count and an empty feed.
     */
    public function recountFor(Post $post): void
    {
        if (! $post instanceof CommentPost || $post->parsed_content === null) {
            return;
        }

        $keys = array_keys($this->visibleNames($post, ignoreHidden: true));

        if (! $keys) {
            return;
        }

        // Resolve without creating — a hashtag that no longer exists needs no
        // recount, and a delete must never bring one into being.
        $ids = Hashtag::query()->whereIn('name_key', $keys)->pluck('id')->all();

        $this->recount(array_map('intval', $ids));
    }

    /**
     * The hashtags a post should currently count for.
     *
     * A hidden post contributes nothing — but its rows are removed rather than
     * flagged, so unhiding has to re-sync (see SyncHashtagsOnPostSave, which
     * listens for Restored as well as Posted/Revised).
     */
    protected function visibleNames(Post $post, bool $ignoreHidden = false): array
    {
        if (! $ignoreHidden && $post->hidden_at !== null) {
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
                array_map(fn (string $key) => [
                    'name' => $names[$key],
                    'name_key' => $key,
                    'created_at' => $this->db->raw('CURRENT_TIMESTAMP'),
                ], array_values($missing))
            );

            $existing = Hashtag::query()->whereIn('name_key', $keys)->pluck('id', 'name_key')->all();
        }

        return array_map('intval', $existing);
    }

    /**
     * Recompute post_count, discussion_count and last_used_at from the pivot
     * for the given hashtags, and delete any that no post uses any more.
     *
     * Recomputed rather than incremented on purpose: an aggregate over the
     * feed index cannot drift, whereas a counter that is incremented on save
     * and decremented on delete drifts the first time a job is retried or a
     * post is removed by a FK cascade (which fires no Flarum event).
     *
     * @param int[] $hashtagIds
     */
    protected function recount(array $hashtagIds): void
    {
        if (! $hashtagIds) {
            return;
        }

        $totals = $this->db->table('post_hashtag')
            ->join('posts', 'posts.id', '=', 'post_hashtag.post_id')
            ->whereIn('post_hashtag.hashtag_id', $hashtagIds)
            ->groupBy('post_hashtag.hashtag_id')
            ->selectRaw('post_hashtag.hashtag_id as hashtag_id')
            ->selectRaw('COUNT(*) as post_count')
            ->selectRaw('COUNT(DISTINCT post_hashtag.discussion_id) as discussion_count')
            ->selectRaw('MAX(posts.created_at) as last_used_at')
            ->get()
            ->keyBy('hashtag_id');

        foreach ($hashtagIds as $id) {
            $row = $totals->get($id);

            if ($row === null) {
                /**
                 * Nothing references it any more. Deleting keeps the
                 * autocomplete and the index page free of hashtags that would
                 * lead to an empty feed; the row is recreated the moment
                 * somebody writes it again.
                 */
                Hashtag::query()->whereKey($id)->delete();

                continue;
            }

            Hashtag::query()->whereKey($id)->update([
                'post_count' => (int) $row->post_count,
                'discussion_count' => (int) $row->discussion_count,
                'last_used_at' => $row->last_used_at,
            ]);
        }
    }
}
