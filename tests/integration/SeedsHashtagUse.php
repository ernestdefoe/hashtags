<?php

namespace Ernestdefoe\Hashtags\Tests\integration;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

/**
 * Real posts behind seeded hashtags. The list and its counts come from the
 * posts the actor can see (VisibleUsage), so a hashtag row on its own is not
 * listed: each spec's post_count, discussion_count and last_used_at become
 * public posts, and only id, name, name_key and created_at go in the row.
 */
trait SeedsHashtagUse
{
    /**
     * @param array<int, array<string, mixed>> $hashtags hashtag rows plus the use to seed behind them
     * @return array<string, array<int, array<string, mixed>>> to merge into prepareDatabase()
     */
    protected function hashtagUse(array $hashtags): array
    {
        $discussions = $posts = $pivot = [];
        $discussionId = $postId = 0;

        foreach ($hashtags as $hashtag) {
            $first = $discussionId + 1;

            for ($i = 0; $i < $hashtag['discussion_count']; $i++) {
                $discussionId++;
                $discussions[] = ['id' => $discussionId, 'title' => "Discussion $discussionId", 'created_at' => Carbon::parse('2026-01-01'), 'user_id' => 1, 'first_post_id' => null, 'comment_count' => 1];
            }

            for ($i = 0; $i < $hashtag['post_count']; $i++) {
                $postId++;
                $discussion = $first + min($i, $hashtag['discussion_count'] - 1);
                $posts[] = ['id' => $postId, 'discussion_id' => $discussion, 'number' => $postId, 'created_at' => $hashtag['last_used_at'] ?? Carbon::parse('2026-01-01'), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>#'.$hashtag['name'].'</p></t>'];
                $pivot[] = ['post_id' => $postId, 'hashtag_id' => $hashtag['id'], 'discussion_id' => $discussion];
            }
        }

        return [
            Discussion::class => $discussions,
            Post::class => $posts,
            'hashtags' => array_map(fn (array $hashtag) => array_diff_key($hashtag, array_flip(['post_count', 'discussion_count', 'last_used_at'])), $hashtags),
            'post_hashtag' => $pivot,
        ];
    }
}
