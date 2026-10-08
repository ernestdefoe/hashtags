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
 * Writing, editing, hiding, restoring and deleting a post keeps the hashtags
 * table and the feed index in step with what posts actually say.
 */
class HashtagSyncTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'One', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>First</p></t>'],
            ],
        ]);
    }

    /** As the admin, who is exempt from the flood throttle between replies. */
    private function reply(string $content): array
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json' => ['data' => [
                'type' => 'posts',
                'attributes' => ['content' => $content],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
            ]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    private function patch(string $postId, array $attributes): void
    {
        $response = $this->send($this->request('PATCH', "/api/posts/$postId", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $postId, 'attributes' => $attributes]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array<string, array{name: string, post_count: int, discussion_count: int}> */
    private function hashtags(): array
    {
        return $this->database()->table('hashtags')->get()->mapWithKeys(fn ($row) => [
            $row->name_key => ['name' => $row->name, 'post_count' => (int) $row->post_count, 'discussion_count' => (int) $row->discussion_count],
        ])->all();
    }

    #[Test]
    public function a_reply_creates_its_hashtags_once_each_folded_to_one_key()
    {
        $post = $this->reply('Ready for #GameDay! Again: #gameday. Not hashtags: C# F# foo#bar #123');

        $this->assertSame(
            ['gameday' => ['name' => 'GameDay', 'post_count' => 1, 'discussion_count' => 1]],
            $this->hashtags()
        );

        $this->assertSame(1, $this->database()->table('post_hashtag')->where('post_id', $post['id'])->where('discussion_id', 1)->count());
    }

    #[Test]
    public function a_rendered_hashtag_links_to_its_folded_feed_and_keeps_the_typed_casing()
    {
        $post = $this->reply('Ready for #GameDay');

        $this->assertStringContainsString('href="http://localhost/hashtag/gameday"', $post['attributes']['contentHtml']);
        $this->assertStringContainsString('>#GameDay</a>', $post['attributes']['contentHtml']);
    }

    #[Test]
    public function editing_a_hashtag_out_of_a_post_removes_it_when_nothing_else_uses_it()
    {
        $post = $this->reply('#alpha and #beta');
        $this->reply('#beta again');

        $this->patch($post['id'], ['content' => 'only #beta now']);

        $this->assertSame(['beta' => ['name' => 'beta', 'post_count' => 2, 'discussion_count' => 1]], $this->hashtags());
    }

    #[Test]
    public function hiding_a_post_retracts_its_hashtags_and_restoring_brings_them_back()
    {
        $post = $this->reply('#alpha');

        $this->patch($post['id'], ['isHidden' => true]);
        $this->assertSame([], $this->hashtags());

        $this->patch($post['id'], ['isHidden' => false]);
        $this->assertSame(['alpha' => ['name' => 'alpha', 'post_count' => 1, 'discussion_count' => 1]], $this->hashtags());
    }

    #[Test]
    public function deleting_a_post_corrects_the_totals()
    {
        $post = $this->reply('#alpha #beta');
        $this->reply('#beta');

        $response = $this->send($this->request('DELETE', '/api/posts/'.$post['id'], ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());

        $this->assertSame(['beta' => ['name' => 'beta', 'post_count' => 1, 'discussion_count' => 1]], $this->hashtags());
    }
}
