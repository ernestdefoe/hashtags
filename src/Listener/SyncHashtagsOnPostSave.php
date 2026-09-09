<?php

namespace Ernestdefoe\Hashtags\Listener;

use Ernestdefoe\Hashtags\Job\SyncHashtagsJob;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Queue\Queue;

/**
 * Keeps `post_hashtag` in step with what posts actually say.
 *
 * Posted/Revised/Hidden/Restored go through the queue — a post with twenty
 * hashtags should not add twenty round-trips to the save request. Deletion is
 * handled separately and inline; see RecountHashtagsOnPostDelete.
 */
class SyncHashtagsOnPostSave
{
    public function __construct(
        protected Queue $queue
    ) {}

    public function handle(Posted|Revised|Hidden|Restored $event): void
    {
        $post = $event->post;

        if (! $post instanceof CommentPost) {
            return;
        }

        $this->queue->push(new SyncHashtagsJob((int) $post->id));
    }
}
