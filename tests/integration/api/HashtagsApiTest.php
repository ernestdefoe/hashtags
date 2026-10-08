<?php

namespace Ernestdefoe\Hashtags\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/hashtags and /api/hashtags/{id|name}: the autocomplete and the
 * browse page. Read-only by design — a hashtag exists because a post says it.
 */
class HashtagsApiTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'hashtags' => [
                ['id' => 1, 'name' => 'GameDay', 'name_key' => 'gameday', 'post_count' => 5, 'discussion_count' => 2, 'last_used_at' => Carbon::parse('2026-01-02'), 'created_at' => Carbon::parse('2026-01-01')],
                ['id' => 2, 'name' => 'gamenight', 'name_key' => 'gamenight', 'post_count' => 9, 'discussion_count' => 3, 'last_used_at' => Carbon::parse('2026-01-03'), 'created_at' => Carbon::parse('2026-01-01')],
                ['id' => 3, 'name' => 'multi_word', 'name_key' => 'multi_word', 'post_count' => 1, 'discussion_count' => 1, 'last_used_at' => Carbon::parse('2026-01-01'), 'created_at' => Carbon::parse('2026-01-01')],
                ['id' => 4, 'name' => 'multiplayer', 'name_key' => 'multiplayer', 'post_count' => 2, 'discussion_count' => 1, 'last_used_at' => Carbon::parse('2026-01-01'), 'created_at' => Carbon::parse('2026-01-01')],
            ],
        ]);
    }

    private function get(string $path, ?int $actor = null, array $query = []): array
    {
        $response = $this->send(
            $this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query)
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return string[] */
    private function names(array $body): array
    {
        return array_map(fn (array $item) => $item['attributes']['name'], $body['data']);
    }

    #[Test]
    public function anyone_can_list_hashtags_most_used_first()
    {
        foreach ([null, 2, 1] as $actor) {
            [$status, $body] = $this->get('/api/hashtags', $actor);

            $this->assertSame(200, $status);
            $this->assertSame(['gamenight', 'GameDay', 'multiplayer', 'multi_word'], $this->names($body));
        }
    }

    #[Test]
    public function serializes_each_attribute()
    {
        [, $body] = $this->get('/api/hashtags');

        $this->assertSame('hashtags', $body['data'][1]['type']);
        $this->assertSame('1', $body['data'][1]['id']);

        $attributes = $body['data'][1]['attributes'];
        $this->assertSame('GameDay', $attributes['name']);
        $this->assertSame('gameday', $attributes['nameKey']);
        $this->assertSame(5, $attributes['postCount']);
        $this->assertSame(2, $attributes['discussionCount']);
        $this->assertSame('2026-01-02T00:00:00+00:00', $attributes['lastUsedAt']);
    }

    #[Test]
    public function the_query_filter_is_a_case_insensitive_prefix_match()
    {
        [$status, $body] = $this->get('/api/hashtags', null, ['filter' => ['q' => 'GAME']]);
        $this->assertSame(200, $status);
        $this->assertSame(['gamenight', 'GameDay'], $this->names($body));

        [, $body] = $this->get('/api/hashtags', null, ['filter' => ['q' => 'day']]);
        $this->assertSame([], $this->names($body), 'A match in the middle of a hashtag is not a prefix');
    }

    #[Test]
    public function like_wildcards_in_the_query_are_matched_literally()
    {
        [, $body] = $this->get('/api/hashtags', null, ['filter' => ['q' => '%']]);
        $this->assertSame([], $this->names($body), '% must not match every hashtag');

        // `_` is a legal hashtag character, so it has to match itself — and
        // only itself, not any single character.
        [, $body] = $this->get('/api/hashtags', null, ['filter' => ['q' => 'multi_']]);
        $this->assertSame(['multi_word'], $this->names($body));
    }

    #[Test]
    public function a_hashtag_can_be_fetched_by_id_or_by_name_in_any_casing()
    {
        foreach (['1', 'gameday', 'GameDay', 'GAMEDAY'] as $id) {
            [$status, $body] = $this->get('/api/hashtags/'.$id);

            $this->assertSame(200, $status, "/api/hashtags/$id");
            $this->assertSame('1', $body['data']['id']);
        }

        [$status] = $this->get('/api/hashtags/nosuchtag');
        $this->assertSame(404, $status);

        [$status] = $this->get('/api/hashtags/999');
        $this->assertSame(404, $status);
    }

    #[Test]
    public function hashtags_cannot_be_created_edited_or_deleted_through_the_api()
    {
        $json = ['data' => ['type' => 'hashtags', 'attributes' => ['name' => 'forged']]];

        $create = $this->send($this->request('POST', '/api/hashtags', ['authenticatedAs' => 1, 'json' => $json]));
        $update = $this->send($this->request('PATCH', '/api/hashtags/1', ['authenticatedAs' => 1, 'json' => ['data' => ['type' => 'hashtags', 'id' => '1', 'attributes' => ['name' => 'forged']]]]));
        $delete = $this->send($this->request('DELETE', '/api/hashtags/1', ['authenticatedAs' => 1]));

        foreach ([$create, $update, $delete] as $response) {
            $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        }

        $this->assertSame(['GameDay', 'gamenight', 'multi_word', 'multiplayer'], $this->database()->table('hashtags')->orderBy('id')->pluck('name')->all());
    }
}
