<?php

namespace Ernestdefoe\Hashtags\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\ConsoleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `hashtags:prune` deletes hashtags no post uses once they are a month old,
 * and nothing else.
 */
class PruneCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        $old = Carbon::now()->subDays(31);
        $new = Carbon::now()->subDays(29);

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'One', 'created_at' => $old, 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => $old, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>#used</p></t>'],
            ],
            'hashtags' => [
                ['id' => 1, 'name' => 'used', 'name_key' => 'used', 'created_at' => $old],
                ['id' => 2, 'name' => 'unused_old', 'name_key' => 'unused_old', 'created_at' => $old],
                ['id' => 3, 'name' => 'unused_new', 'name_key' => 'unused_new', 'created_at' => $new],
            ],
            'post_hashtag' => [
                ['post_id' => 1, 'hashtag_id' => 1, 'discussion_id' => 1],
            ],
        ]);
    }

    #[Test]
    public function it_deletes_only_unused_hashtags_older_than_a_month()
    {
        $output = $this->runCommand(['command' => 'hashtags:prune']);

        $this->assertStringContainsString('Deleted 1 unused hashtags.', $output);
        $this->assertSame(['unused_new', 'used'], $this->database()->table('hashtags')->orderBy('name_key')->pluck('name_key')->all());
        $this->assertSame(1, $this->database()->table('post_hashtag')->count());
    }

    #[Test]
    public function it_is_scheduled_daily_without_overlapping()
    {
        $schedule = $this->app()->getContainer()->make(\Illuminate\Console\Scheduling\Schedule::class);

        $events = array_values(array_filter(
            $schedule->events(),
            fn ($event) => str_contains((string) $event->command, 'hashtags:prune')
        ));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    #[Test]
    public function a_used_hashtag_cannot_be_deleted_out_from_under_its_posts()
    {
        if ($this->database()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('The SQLite test database does not enforce foreign keys.');
        }

        $this->app();
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->database()->table('hashtags')->where('id', 1)->delete();
    }

    #[Test]
    public function a_sync_recovers_when_its_hashtag_is_pruned_mid_write()
    {
        if ($this->database()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('The SQLite test database does not enforce foreign keys.');
        }

        $this->app();
        $db = $this->database();
        $db->table('posts')->insert(['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment',
            'content' => '<r><p><HASHTAG key="unused_old" name="unused_old">#unused_old</HASHTAG></p></r>']);

        // Delete #unused_old the first time the sync is about to insert its
        // row, as a prune would. On the same connection the delete is part of
        // the sync's transaction, so the failed attempt's rollback restores it:
        // what this proves is that the foreign key catches the insert and the
        // sync runs again instead of failing.
        $inserts = 0;
        $db->beforeExecuting(function (string $sql) use ($db, &$inserts) {
            if (str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, 'post_hashtag') && ++$inserts === 1) {
                $db->table('hashtags')->where('id', 2)->delete();
            }
        });

        $this->app()->getContainer()->make(\Ernestdefoe\Hashtags\Hashtag\HashtagSyncer::class)
            ->sync(\Flarum\Post\CommentPost::query()->findOrFail(2));

        $this->assertSame(2, $inserts, 'the sync tried once, hit the foreign key, and ran again');
        $id = $db->table('hashtags')->where('name_key', 'unused_old')->value('id');
        $this->assertSame(1, $db->table('post_hashtag')->where(['post_id' => 2, 'hashtag_id' => $id])->count());
    }
}
