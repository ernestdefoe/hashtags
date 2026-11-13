<?php

namespace Ernestdefoe\Hashtags\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Hashtags shown per page": the admin setting the browse page reads from the
 * forum attributes, and the API page cap that has to let it through.
 */
class PerPageSettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');
    }

    private function forumAttribute(): mixed
    {
        $response = $this->send($this->request('GET', '/api'));

        return json_decode((string) $response->getBody(), true)['data']['attributes']['hashtagsPerPage'] ?? null;
    }

    #[Test]
    public function defaults_to_60()
    {
        $this->assertSame(60, $this->forumAttribute());
    }

    #[Test]
    public function serializes_a_custom_value()
    {
        $this->setting('ernestdefoe-hashtags.per_page', '25');

        $this->assertSame(25, $this->forumAttribute());
    }

    public static function outOfRange(): array
    {
        return [
            'above the API cap' => ['500', 100],
            'zero' => ['0', 1],
            'negative' => ['-5', 1],
            'not a number' => ['lots', 60],
            'blank' => ['', 60],
        ];
    }

    #[Test]
    #[DataProvider('outOfRange')]
    public function clamps_values_the_api_would_not_honour(string $saved, int $expected)
    {
        $this->setting('ernestdefoe-hashtags.per_page', $saved);

        $this->assertSame($expected, $this->forumAttribute());
    }

    #[Test]
    public function the_api_serves_up_to_100_per_page()
    {
        $rows = [];
        for ($i = 1; $i <= 110; $i++) {
            $rows[] = ['id' => $i, 'name' => "tag$i", 'name_key' => "tag$i", 'post_count' => 1, 'discussion_count' => 1, 'last_used_at' => Carbon::parse('2026-01-01'), 'created_at' => Carbon::parse('2026-01-01')];
        }
        $this->prepareDatabase(['hashtags' => $rows]);

        $count = fn (string $limit) => count(json_decode((string) $this->send(
            $this->request('GET', '/api/hashtags')->withQueryParams(['page' => ['limit' => $limit]])
        )->getBody(), true)['data']);

        // Core's default cap is 50, which silently trimmed the old hard-coded 60.
        $this->assertSame(60, $count('60'));
        $this->assertSame(100, $count('100'));
        $this->assertSame(100, $count('500'));
    }
}
