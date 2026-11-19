<?php

namespace Ernestdefoe\Hashtags\Job;

use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;

/**
 * Reconcile one post's hashtags off-request.
 *
 * Only the post ID is carried, never the model: by the time the job runs the
 * post may have been edited again or deleted, and a serialised copy would
 * write stale hashtags back over the newer ones.
 */
class SyncHashtagsJob extends AbstractJob
{
    public function __construct(protected int $postId)
    {
        parent::__construct();
    }

    public function handle(HashtagSyncer $syncer): void
    {
        $post = Post::query()->find($this->postId);

        // Deleted between save and run: the FK cascade already removed the
        // post's rows, so there is nothing to sync.
        if ($post === null) {
            return;
        }

        $syncer->sync($post);
    }
}
