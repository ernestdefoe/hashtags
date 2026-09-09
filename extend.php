<?php

use Ernestdefoe\Hashtags\Api\Resource\HashtagResource;
use Ernestdefoe\Hashtags\Console\ReindexCommand;
use Ernestdefoe\Hashtags\Formatter\ConfigureHashtags;
use Ernestdefoe\Hashtags\Listener\SyncHashtagsOnPostSave;
use Ernestdefoe\Hashtags\Model\Hashtag;
use Ernestdefoe\Hashtags\Search\Filter\HashtagFilter;
use Ernestdefoe\Hashtags\Search\FulltextFilter;
use Ernestdefoe\Hashtags\Search\HashtagSearcher;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Revised;
use Flarum\Search\Database\DatabaseSearchDriver;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')
        /**
         * Route names matter beyond the frontend: ConfigureHashtags builds the
         * href for every rendered hashtag from `hashtag`, via the UrlGenerator.
         * Renaming either route means clearing the formatter cache, because
         * that prefix is baked into the compiled renderer.
         */
        ->route('/hashtag/{name}', 'hashtag')
        ->route('/hashtags', 'hashtags'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Formatter())
        ->configure(ConfigureHashtags::class),

    (new Extend\ApiResource(HashtagResource::class)),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        /** The /hashtag/:name feed, and `hashtag:x` in the search box. */
        ->addFilter(DiscussionSearcher::class, HashtagFilter::class)
        /** GET /api/hashtags — browse page and composer autocomplete. */
        ->addSearcher(Hashtag::class, HashtagSearcher::class)
        ->setFulltext(HashtagSearcher::class, FulltextFilter::class),

    /**
     * Backfill for forums that installed this after they already had posts —
     * see ReindexCommand for why re-parsing is the only way old posts can gain
     * hashtag links.
     */
    (new Extend\Console())
        ->command(ReindexCommand::class),

    (new Extend\Event())
        ->listen(Posted::class, SyncHashtagsOnPostSave::class)
        ->listen(Revised::class, SyncHashtagsOnPostSave::class)
        /**
         * Hiding a post retracts its hashtags; restoring reinstates them. Both
         * go through the same queued sync as an edit — the syncer reads
         * hidden_at rather than being told which way it moved.
         */
        ->listen(Hidden::class, SyncHashtagsOnPostSave::class)
        ->listen(Restored::class, SyncHashtagsOnPostSave::class)
        /**
         * Deletion is handled inline, not queued. The FK cascade has already
         * removed the pivot rows by the time this fires, so the only remaining
         * record of what the post used is the model still held in the event —
         * which cannot survive serialisation into a job.
         */
        ->listen(Deleted::class, [SyncHashtagsOnPostSave::class, 'handleDeleted']),
];
