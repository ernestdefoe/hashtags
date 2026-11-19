<?php

namespace Ernestdefoe\Hashtags\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Hashtags\Tests\integration\SeedsHashtagUse;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guests share one cached answer for the unfiltered first page; members,
 * filtered requests and later pages always count live.
 */
class GuestListCacheTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsHashtagUse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ] + $this->hashtagUse([
            ['id' => 1, 'name' => 'alpha', 'name_key' => 'alpha', 'post_count' => 2, 'discussion_count' => 1, 'created_at' => Carbon::parse('2026-01-01')],
        ]));
    }

    /** @return array<string, int> name => postCount */
    private function listed(?int $actor, array $query = []): array
    {
        $response = $this->send(
            $this->request('GET', '/api/hashtags', $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query)
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $out = [];
        foreach (json_decode((string) $response->getBody(), true)['data'] as $item) {
            $out[$item['attributes']['name']] = $item['attributes']['postCount'];
        }

        return $out;
    }

    /** One more public use of #alpha, written straight to the database. */
    private function addUse(): void
    {
        $db = $this->database();
        $db->table('posts')->insert(['id' => 50, 'discussion_id' => 1, 'number' => 50, 'created_at' => Carbon::parse('2026-01-02'), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>#alpha</p></t>']);
        $db->table('post_hashtag')->insert(['post_id' => 50, 'hashtag_id' => 1, 'discussion_id' => 1]);
    }

    #[Test]
    public function guests_get_the_cached_list_and_members_count_live()
    {
        $this->assertSame(['alpha' => 2], $this->listed(null));

        $this->addUse();

        $this->assertSame(['alpha' => 2], $this->listed(null), 'guests share the cached answer');
        $this->assertSame(['alpha' => 3], $this->listed(2), 'members are never cached');
        $this->assertSame(['alpha' => 3], $this->listed(null, ['filter' => ['q' => 'al']]), 'filtered requests are never cached');
        $this->assertSame(['alpha' => 3], $this->listed(null, ['sort' => 'name']), 'each sort has its own entry');
    }

    #[Test]
    public function a_member_never_receives_the_guest_answer()
    {
        // Warm the guest entry, then change what a member would see.
        $this->listed(null);
        $this->addUse();

        $this->assertSame(['alpha' => 3], $this->listed(2));
        $this->assertSame(['alpha' => 3], $this->listed(1));
    }
}
