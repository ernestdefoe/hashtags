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
 * The hashtag list and its counts follow post visibility: a hashtag used only
 * where the actor cannot look is not listed, and a hashtag used both in the
 * open and in private counts only the open use.
 */
class HashtagVisibilityTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        $post = fn (int $id, int $discussion, array $extra = []) => $extra + [
            'id' => $id, 'discussion_id' => $discussion, 'number' => $id, 'created_at' => Carbon::parse("2026-01-0$id"),
            'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>#tag</p></t>',
        ];
        $discussion = fn (int $id, array $extra = []) => $extra + [
            'id' => $id, 'title' => "Discussion $id", 'created_at' => Carbon::parse('2026-01-01'), 'user_id' => 2, 'first_post_id' => $id, 'comment_count' => 1,
        ];

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                $discussion(1),
                // A hidden discussion: only moderators (here the admin) can see it.
                $discussion(2, ['hidden_at' => Carbon::parse('2026-01-02'), 'user_id' => 1]),
                // A private discussion (the kind byobu makes): nobody without a grant.
                $discussion(3, ['is_private' => true, 'user_id' => 1]),
            ],
            Post::class => [
                $post(1, 1),
                $post(2, 2, ['user_id' => 1]),
                $post(3, 3, ['user_id' => 1]),
                // A private post in a public discussion.
                $post(4, 1, ['is_private' => true, 'user_id' => 1]),
                $post(5, 1),
            ],
            'hashtags' => [
                // Stored totals count every use, private included.
                ['id' => 1, 'name' => 'public', 'name_key' => 'public', 'post_count' => 3, 'discussion_count' => 2, 'last_used_at' => Carbon::parse('2026-01-03'), 'created_at' => Carbon::parse('2026-01-01')],
                ['id' => 2, 'name' => 'moderated', 'name_key' => 'moderated', 'post_count' => 1, 'discussion_count' => 1, 'last_used_at' => Carbon::parse('2026-01-02'), 'created_at' => Carbon::parse('2026-01-01')],
                ['id' => 3, 'name' => 'private', 'name_key' => 'private', 'post_count' => 2, 'discussion_count' => 2, 'last_used_at' => Carbon::parse('2026-01-04'), 'created_at' => Carbon::parse('2026-01-01')],
            ],
            'post_hashtag' => [
                ['post_id' => 1, 'hashtag_id' => 1, 'discussion_id' => 1],
                ['post_id' => 5, 'hashtag_id' => 1, 'discussion_id' => 1],
                ['post_id' => 2, 'hashtag_id' => 1, 'discussion_id' => 2],
                ['post_id' => 2, 'hashtag_id' => 2, 'discussion_id' => 2],
                ['post_id' => 3, 'hashtag_id' => 3, 'discussion_id' => 3],
                ['post_id' => 4, 'hashtag_id' => 3, 'discussion_id' => 1],
            ],
        ]);
    }

    /** @return array<string, array{int, int}> name => [postCount, discussionCount] */
    private function listed(?int $actor, array $query = []): array
    {
        $response = $this->send(
            $this->request('GET', '/api/hashtags', $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query)
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $out = [];
        foreach (json_decode((string) $response->getBody(), true)['data'] as $item) {
            $out[$item['attributes']['name']] = [$item['attributes']['postCount'], $item['attributes']['discussionCount']];
        }

        return $out;
    }

    private function show(string $name, ?int $actor): array
    {
        $response = $this->send($this->request('GET', "/api/hashtags/$name", $actor ? ['authenticatedAs' => $actor] : []));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)['data']['attributes'] ?? null];
    }

    #[Test]
    public function guests_and_members_see_only_public_use()
    {
        foreach ([null, 2] as $actor) {
            $this->assertSame(['public' => [2, 1]], $this->listed($actor));
            $this->assertSame(['public' => [2, 1]], $this->listed($actor, ['filter' => ['q' => 'p']]));
        }
    }

    #[Test]
    public function an_admin_also_sees_what_moderators_can_see()
    {
        $this->assertSame(['public' => [3, 2], 'moderated' => [1, 1]], $this->listed(1));
    }

    #[Test]
    public function a_single_hashtag_follows_the_same_rule()
    {
        foreach ([null, 2] as $actor) {
            $this->assertSame(404, $this->show('private', $actor)[0]);
            $this->assertSame(404, $this->show('moderated', $actor)[0]);

            [$status, $attributes] = $this->show('public', $actor);
            $this->assertSame(200, $status);
            $this->assertSame([2, 1], [$attributes['postCount'], $attributes['discussionCount']]);
            $this->assertSame('2026-01-05T00:00:00+00:00', $attributes['lastUsedAt']);
        }

        [, $attributes] = $this->show('moderated', 1);
        $this->assertSame(1, $attributes['postCount']);
    }

    #[Test]
    public function sorting_follows_the_visible_counts()
    {
        // Stored totals put #private (last used 01-04) first; for a guest it isn't listed at all.
        $response = $this->send($this->request('GET', '/api/hashtags')->withQueryParams(['sort' => '-lastUsedAt']));
        $names = array_map(fn ($i) => $i['attributes']['name'], json_decode((string) $response->getBody(), true)['data']);

        $this->assertSame(['public'], $names);
    }
}
