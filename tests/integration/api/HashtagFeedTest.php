<?php

namespace Ernestdefoe\Hashtags\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The /hashtag/:name feed is a discussion search with `filter[hashtag]`, so
 * it must inherit discussion visibility: a hashtag used only in a discussion
 * the actor cannot see yields nothing.
 */
class HashtagFeedTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        $discussions = [];
        $posts = [];
        $pivot = [];

        // Discussions 1–20 all use #gameday, so a per-discussion query in the
        // filter would show up as an N+1 when they are listed.
        for ($id = 1; $id <= 20; $id++) {
            $discussions[] = ['id' => $id, 'title' => "Discussion $id", 'created_at' => Carbon::now()->subMinutes(100 - $id), 'last_posted_at' => Carbon::now()->subMinutes(100 - $id), 'user_id' => 2, 'first_post_id' => $id, 'comment_count' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<r><p><HASHTAG key="gameday" name="gameday">#gameday</HASHTAG></p></r>'];
            $pivot[] = ['post_id' => $id, 'hashtag_id' => 1, 'discussion_id' => $id];
        }

        // 21 does not use it; 22 uses #secret but is hidden, so only its author (the admin) sees it.
        $discussions[] = ['id' => 21, 'title' => 'Unrelated', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 21, 'comment_count' => 1];
        $posts[] = ['id' => 21, 'discussion_id' => 21, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Nothing</p></t>'];

        $discussions[] = ['id' => 22, 'title' => 'Hidden', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 22, 'comment_count' => 1, 'hidden_at' => Carbon::now()];
        $posts[] = ['id' => 22, 'discussion_id' => 22, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<r><p><HASHTAG key="secret" name="secret">#secret</HASHTAG></p></r>'];
        $pivot[] = ['post_id' => 22, 'hashtag_id' => 2, 'discussion_id' => 22];

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => $discussions,
            Post::class => $posts,
            'hashtags' => [
                ['id' => 1, 'name' => 'GameDay', 'name_key' => 'gameday', 'post_count' => 20, 'discussion_count' => 20],
                ['id' => 2, 'name' => 'secret', 'name_key' => 'secret', 'post_count' => 1, 'discussion_count' => 1],
            ],
            'post_hashtag' => $pivot,
        ]);
    }

    /** @return int[] */
    private function feed(array $filter, ?int $actor = null): array
    {
        $response = $this->send(
            $this->request('GET', '/api/discussions', $actor ? ['authenticatedAs' => $actor] : [])
                ->withQueryParams(['filter' => $filter, 'page' => ['limit' => 50]])
        );

        $this->assertSame(200, $response->getStatusCode());

        $ids = array_map(fn (array $item) => (int) $item['id'], json_decode((string) $response->getBody(), true)['data']);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function the_feed_lists_only_discussions_using_the_hashtag_in_any_casing()
    {
        $this->assertSame(range(1, 20), $this->feed(['hashtag' => 'GameDay']));
        $this->assertSame(range(1, 20), $this->feed(['hashtag' => 'gameday'], 2));
    }

    #[Test]
    public function a_negated_hashtag_excludes_those_discussions()
    {
        $this->assertSame([21], $this->feed(['-hashtag' => 'gameday']));
    }

    #[Test]
    public function the_feed_respects_discussion_visibility()
    {
        $this->assertSame([], $this->feed(['hashtag' => 'secret']), 'A guest cannot see the hidden discussion');
        $this->assertSame([], $this->feed(['hashtag' => 'secret'], 2), 'Nor can a member');
        $this->assertSame([22], $this->feed(['hashtag' => 'secret'], 1), 'Its author, an admin, can');
    }

    #[Test]
    public function an_unknown_hashtag_has_an_empty_feed()
    {
        $this->assertSame([], $this->feed(['hashtag' => 'nosuchtag']));
    }
}
