<?php

namespace Ernestdefoe\Hashtags\Listener;

use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Ernestdefoe\Hashtags\Job\SyncHashtagsJob;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Queue\Queue;

/**
 * Keeps `post_hashtag` in step with what posts actually say.
 *
 * Posted/Revised/Hidden/Restored go through the queue — a post with twenty
 * hashtags should not add twenty round-trips to the save request. Deleted is
 * handled inline because the work depends on the in-memory post model, which
 * cannot survive into a job (the row is already gone).
 */
class SyncHashtagsOnPostSave
{
    public function __construct(
        protected Queue $queue,
        protected HashtagSyncer $syncer
    ) {}

    public function handle(Posted|Revised|Hidden|Restored $event): void
    {
        $post = $event->post;

        if (! $post instanceof CommentPost) {
            return;
        }

        $this->queue->push(new SyncHashtagsJob((int) $post->id));
    }

    public function handleDeleted(Deleted $event): void
    {
        $this->syncer->recountFor($event->post);
    }
}
