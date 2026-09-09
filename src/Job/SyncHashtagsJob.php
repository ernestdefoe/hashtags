<?php

namespace Ernestdefoe\Hashtags\Job;

use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Flarum\Post\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reconcile one post's hashtags off-request.
 *
 * Only the post ID is carried, never the model: by the time the job runs the
 * post may have been edited again or deleted, and a serialised copy would
 * write stale hashtags back over the newer ones.
 */
class SyncHashtagsJob implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(protected int $postId) {}

    public function handle(HashtagSyncer $syncer): void
    {
        $post = Post::query()->find($this->postId);

        // Deleted between save and run — the FK cascade already removed the
        // pivot rows, and the Deleted listener has done the recount.
        if ($post === null) {
            return;
        }

        $syncer->sync($post);
    }
}
