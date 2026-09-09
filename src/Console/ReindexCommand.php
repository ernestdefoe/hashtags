<?php

namespace Ernestdefoe\Hashtags\Console;

use Ernestdefoe\Hashtags\Formatter\ConfigureHashtags;
use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Post\CommentPost;
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
 */
class ReindexCommand extends AbstractCommand
{
    public function __construct(
        protected Formatter $formatter,
        protected HashtagSyncer $syncer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hashtags:reindex')
            ->setDescription('Re-parse existing posts so hashtags written before this extension was installed become links.')
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

        $scanned = 0;
        $changed = 0;

        CommentPost::query()
            /**
             * Cheap pre-filter. Posts with no `#` anywhere in their stored XML
             * cannot contain a hashtag, and skipping them in SQL avoids
             * unparsing the entire post table.
             */
            ->where('content', 'like', '%#%')
            ->orderBy('id')
            ->chunkById($chunk, function ($posts) use ($dryRun, &$scanned, &$changed) {
                foreach ($posts as $post) {
                    $scanned++;

                    $source = $this->formatter->unparse($post->content, $post);

                    if ($source === null || ! preg_match(ConfigureHashtags::REGEX, $source)) {
                        continue;
                    }

                    $reparsed = $this->formatter->parse($source, $post, $post->user);

                    // Identical XML means the post already had its hashtags
                    // parsed (a re-run, or it was edited after install). Not
                    // writing keeps edited_at and the post row untouched.
                    if ($reparsed === $post->content) {
                        continue;
                    }

                    $changed++;

                    if ($dryRun) {
                        continue;
                    }

                    /**
                     * Written with a direct UPDATE rather than $post->save():
                     * saving would fire Revised, which would queue a sync job
                     * per post on top of the one we run inline — and on a big
                     * forum that floods the queue with duplicate work.
                     */
                    $post->newQuery()->whereKey($post->id)->update(['content' => $reparsed]);

                    $post->content = $reparsed;

                    $this->syncer->sync($post);
                }

                $this->info("Scanned $scanned posts, updated $changed…");
            });

        $this->info($dryRun
            ? "Done. $changed of $scanned posts would gain hashtags."
            : "Done. $changed of $scanned posts updated.");

        return 0;
    }
}
