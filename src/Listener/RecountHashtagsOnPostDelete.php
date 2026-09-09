<?php

namespace Ernestdefoe\Hashtags\Listener;

use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Flarum\Post\Event\Deleted;

/**
 * Correct hashtag totals after a post is hard-deleted.
 *
 * Separate from SyncHashtagsOnPostSave, and not queued, for two reasons:
 *
 *  1. Extend\Event::listen() types its listener `callable|string`, and
 *     [Class::class, 'method'] is NOT callable for an instance method — so a
 *     second handler on the same class cannot be registered. One event, one
 *     listener class.
 *  2. The pivot rows are already gone (FK cascade) by the time this fires. The
 *     only remaining record of which hashtags the post used is the model held
 *     in the event, which cannot survive serialisation into a job.
 */
class RecountHashtagsOnPostDelete
{
    public function __construct(
        protected HashtagSyncer $syncer
    ) {}

    public function handle(Deleted $event): void
    {
        $this->syncer->recountFor($event->post);
    }
}
