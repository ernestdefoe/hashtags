<?php

namespace Ernestdefoe\Hashtags\Console;

use Ernestdefoe\Hashtags\Formatter\ConfigureHashtags;
use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Post\CommentPost;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Input\InputOption;

/**
 * Backfill hashtags from posts written before this extension was installed.
 *
 * Why this is needed at all: Flarum stores posts as PARSED XML. A post written
 * before the extension existed has `#gameday` sitting in a text node, not a
 * HASHTAG element — so it neither renders as a link nor appears in any feed,
 * however many times the page is reloaded. Only re-parsing fixes that, and
 * re-parsing is what Flarum itself does whenever a post is edited.
 *
 * The risk being managed here is that unparse → parse is a round trip through
 * every OTHER formatter extension too. So this command touches as few posts as
 * possible:
 *
 *   1. SQL pre-filter: only posts whose content contains a `#` at all.
 *   2. Regex pre-filter on the unparsed source: only posts where the hashtag
 *      pattern actually matches.
 *
 * On a typical forum that leaves single-digit percentages of posts rewritten.
 * Everything else is read and skipped without ever being written back.
 *
 * Every post with a hashtag is then SYNCED, rewritten or not: a post can be
 * parsed and still have no rows (written while the queue was down, imported,
 * inserted by a script), and only reindexing fixes that. The first version
 * synced only the posts it rewrote, so those stayed missing from every feed.
 */
class ReindexCommand extends AbstractCommand
{
    protected const SKIPPED = 'skipped';
    protected const INDEXED = 'indexed';
    protected const REPARSED = 'reparsed';

    public function __construct(
        protected Formatter $formatter,
        protected HashtagSyncer $syncer,
        protected ConnectionInterface $db
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hashtags:reindex')
            ->setDescription('Re-parse posts written before this extension was installed and rebuild the hashtag index from every post.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would change without writing anything.'
            )
            ->addOption(
                'chunk',
                null,
                InputOption::VALUE_REQUIRED,
                'How many posts to load per batch.',
                500
            );
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $chunk = max(1, (int) $this->input->getOption('chunk'));

        if ($dryRun) {
            $this->info('Dry run — no posts will be modified.');
        }

        $totals = ['scanned' => 0, 'changed' => 0, 'indexed' => 0];

        CommentPost::query()
            /**
             * Cheap pre-filter. Posts with no `#` anywhere in their stored XML
             * cannot contain a hashtag, and skipping them in SQL avoids
             * unparsing the entire post table. Posts that already have rows
             * are included too, so a row left behind for a post that no
             * longer says the hashtag is removed.
             */
            ->where(fn ($q) => $q
                ->where('content', 'like', '%#%')
                ->orWhereIn('id', fn ($sub) => $sub->select('post_id')->from('post_hashtag')))
            // The formatter parses as the post's author: one query per chunk, not per post.
            ->with('user')
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $posts) use ($dryRun, &$totals) {
                $this->reindexChunk($posts, $dryRun, $totals);

                // Braces are load-bearing: PHP identifiers may contain bytes >= 0x80, so
                // "$changed…" parses as a variable named "changed…" and interpolates
                // as empty. Reported "updated " with no number until this was braced.
                $this->info("Scanned {$totals['scanned']} posts, re-parsed {$totals['changed']}, indexed {$totals['indexed']}…");
            });

        $this->info($dryRun
            ? "Done. {$totals['changed']} of {$totals['scanned']} posts would be re-parsed, {$totals['indexed']} posts would be indexed."
            : "Done. {$totals['changed']} of {$totals['scanned']} posts re-parsed, {$totals['indexed']} posts indexed.");

        return 0;
    }

    /**
     * @param Collection<int, CommentPost> $posts
     * @param array{scanned: int, changed: int, indexed: int} $totals
     */
    protected function reindexChunk(Collection $posts, bool $dryRun, array &$totals): void
    {
        $hasRows = $this->postsWithRows($posts);
        $toSync = [];

        foreach ($posts as $post) {
            $totals['scanned']++;

            $outcome = $this->reindex($post, isset($hasRows[(int) $post->id]), $dryRun);

            if ($outcome !== self::SKIPPED) {
                $totals['indexed']++;
                $toSync[] = $post;
            }

            if ($outcome === self::REPARSED) {
                $totals['changed']++;
            }
        }

        if (! $dryRun) {
            // A full diff-and-replace for each post, so a re-run is a no-op.
            // One batch per chunk, not a round of queries per post.
            $this->syncer->syncMany($toSync);
        }
    }

    /**
     * Which of these posts already have rows, in one query per chunk.
     *
     * @param Collection<int, CommentPost> $posts
     * @return array<int, int> post id => index
     */
    protected function postsWithRows(Collection $posts): array
    {
        return array_flip($this->db->table('post_hashtag')
            ->whereIn('post_id', $posts->modelKeys())
            ->distinct()
            ->pluck('post_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * Re-parse one post if it needs it; the caller syncs its rows.
     *
     * @return string SKIPPED, INDEXED or REPARSED
     */
    protected function reindex(CommentPost $post, bool $hasRows, bool $dryRun): string
    {
        /**
         * `$post->content` is already the unparsed source —
         * HasFormattedContent's accessor unparses on read. Calling the
         * formatter's unparse() on it again would be a second round trip over
         * text that is no longer XML.
         */
        $source = $post->content;
        $matches = $source !== null && preg_match(ConfigureHashtags::REGEX, $source);

        /**
         * 🚨 The second reason to re-parse, and the one this command exists for.
         *
         * `$matches` asks "does this post's text contain a hashtag by TODAY's
         * rules". A post that needs repairing is the exact opposite: it holds
         * a HASHTAG element that today's rules would NOT produce — stored by an
         * older version, by an importer, or left behind when an exclusion was
         * added.
         *
         * Gating the re-parse on `$matches` alone skipped every one of them.
         * After 2.0.7 excluded `#p123`, a reply holding a stray
         * `<HASHTAG name="p1233">` stopped matching, so its row was cleared
         * while the element itself stayed in posts.content for ever: one forum
         * reported 1 post re-parsed out of 17,700 and ~1,050 replies left to
         * fix by hand in SQL.
         */
        $hasStored = str_contains((string) $post->parsed_content, '<'.ConfigureHashtags::TAG);

        // Nothing in the text, nothing stored, no rows to clear.
        if (! $matches && ! $hasStored && ! $hasRows) {
            return self::SKIPPED;
        }

        return ($matches || $hasStored) && $source !== null && $this->reparse($post, $source, $dryRun)
            ? self::REPARSED
            : self::INDEXED;
    }

    /**
     * Re-parse a post's source and write it back if the XML changed.
     *
     * Identical XML means the post already had its hashtags parsed (a re-run,
     * or it was edited after install). Not writing keeps edited_at and the
     * post row untouched — but it is still synced: parsed is not indexed.
     *
     * @return bool whether the XML changed
     */
    protected function reparse(CommentPost $post, string $source, bool $dryRun): bool
    {
        $reparsed = $this->formatter->parse($source, $post, $post->user);

        if ($reparsed === $post->parsed_content) {
            return false;
        }

        if (! $dryRun) {
            /**
             * Written with a direct UPDATE rather than $post->save(): saving
             * would fire Revised, which would queue a sync job per post on top
             * of the one run inline — and on a big forum that floods the queue
             * with duplicate work.
             */
            $post->newQuery()->whereKey($post->id)->update(['content' => $reparsed]);

            // setParsedContentAttribute, not `->content =`, which would send
            // the XML back through the parser a second time.
            $post->setParsedContentAttribute($reparsed);
        }

        return true;
    }
}
