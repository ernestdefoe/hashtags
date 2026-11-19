<?php

namespace Ernestdefoe\Hashtags\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use PHPUnit\Framework\Attributes\Test;

/**
 * An install from before 2.2 has counter columns and hand-named indexes; the
 * 2026_11_19 migration removes the columns and gives the indexes generated
 * names. A fresh install has neither, and the migration must pass over it.
 */
class DropCountersMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        // Boot the app so the extension's migrations have run.
        $this->app();
    }

    private function schema(): Builder
    {
        return $this->database()->getSchemaBuilder();
    }

    private function migration(): array
    {
        return require __DIR__.'/../../../migrations/2026_11_19_000000_drop_hashtag_counters.php';
    }

    /** Recreate the pre-2.2 shape on top of the fresh tables. */
    private function makeOldSchema(): void
    {
        $schema = $this->schema();
        $prefixed = $this->database()->getTablePrefix() !== '';

        $schema->table('hashtags', function (Blueprint $table) {
            $table->unsignedInteger('post_count')->default(0);
            $table->unsignedInteger('discussion_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
        });

        $schema->table('hashtags', function (Blueprint $table) use ($prefixed) {
            $table->index(['post_count', 'last_used_at'], 'hashtags_popularity_idx');

            if ($prefixed) {
                $table->dropUnique(['name_key']);
                $table->unique('name_key', 'hashtags_name_key_unique');
            }
        });

        $schema->table('post_hashtag', function (Blueprint $table) {
            $table->index(['hashtag_id', 'discussion_id'], 'post_hashtag_feed_idx');
            $table->dropIndex(['hashtag_id', 'discussion_id']);
        });

        $schema->table('post_hashtag', function (Blueprint $table) {
            $table->dropForeign(['hashtag_id']);
            $table->foreign('hashtag_id')->references('id')->on('hashtags')->cascadeOnDelete();
        });
    }

    private function hashtagOnDelete(): string
    {
        foreach ($this->schema()->getForeignKeys('post_hashtag') as $key) {
            if ($key['columns'] === ['hashtag_id']) {
                return strtolower((string) $key['on_delete']);
            }
        }

        return 'none';
    }

    /** @return string[] lower-cased index names */
    private function indexes(string $table): array
    {
        return array_column($this->schema()->getIndexes($table), 'name');
    }

    #[Test]
    public function it_converts_an_old_install()
    {
        $this->makeOldSchema();

        ($this->migration()['up'])($this->schema());

        $schema = $this->schema();
        $prefix = strtolower($this->database()->getTablePrefix());

        foreach (['post_count', 'discussion_count', 'last_used_at'] as $column) {
            $this->assertFalse($schema->hasColumn('hashtags', $column), "$column should be gone");
        }

        $this->assertSame('restrict', $this->hashtagOnDelete());

        $this->assertNotContains('hashtags_popularity_idx', $this->indexes('hashtags'));
        $this->assertNotContains('post_hashtag_feed_idx', $this->indexes('post_hashtag'));
        $this->assertContains($prefix.'hashtags_name_key_unique', $this->indexes('hashtags'));
        $this->assertContains($prefix.'post_hashtag_hashtag_id_discussion_id_index', $this->indexes('post_hashtag'));

        // Again, on the converted schema: every step finds nothing to do.
        $after = [$this->indexes('hashtags'), $this->indexes('post_hashtag')];
        ($this->migration()['up'])($this->schema());
        $this->assertSame($after, [$this->indexes('hashtags'), $this->indexes('post_hashtag')]);
    }

    #[Test]
    public function it_passes_over_a_fresh_install_and_rolls_back()
    {
        $before = [$this->indexes('hashtags'), $this->indexes('post_hashtag')];
        $this->assertSame('restrict', $this->hashtagOnDelete(), 'a fresh install restricts already');

        ($this->migration()['up'])($this->schema());

        $this->assertSame($before, [$this->indexes('hashtags'), $this->indexes('post_hashtag')]);

        ($this->migration()['down'])($this->schema());

        $this->assertTrue($this->schema()->hasColumn('hashtags', 'post_count'));
        $this->assertSame('cascade', $this->hashtagOnDelete(), 'down() restores the cascade');
        $this->assertContains(
            ['post_count', 'last_used_at'],
            array_column($this->schema()->getIndexes('hashtags'), 'columns'),
            'down() restores the counters index'
        );

        // Again, which also leaves the schema as the other tests expect it:
        // schema changes are not undone by the test's transaction on MySQL.
        ($this->migration()['up'])($this->schema());

        $this->assertFalse($this->schema()->hasColumn('hashtags', 'post_count'));
        $this->assertSame('restrict', $this->hashtagOnDelete());
    }
}
