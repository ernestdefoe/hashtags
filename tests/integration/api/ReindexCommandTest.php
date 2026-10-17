<?php

namespace Ernestdefoe\Hashtags\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\ConsoleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `hashtags:reindex` rebuilds the index from what posts say — including posts
 * that were already parsed but never synced (written while the queue was down,
 * or inserted straight into the database), and stale rows for posts that no
 * longer carry a hashtag.
 */
class ReindexCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-hashtags');

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'One', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 4],
            ],
        ]);
    }

    #[Test]
    public function it_indexes_parsed_posts_that_were_never_synced_and_drops_stale_rows()
    {
        $formatter = $this->app()->getContainer()->make(Formatter::class);
        $db = $this->database();

        // Written around the events: parsed, but no sync ever ran.
        $db->table('posts')->insert([
            ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => $formatter->parse('Kickoff #gameday')],
            ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => $formatter->parse('More #GameDay and #tailgate')],
            ['id' => 3, 'discussion_id' => 1, 'number' => 3, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t>No tags here</t>'],
            // Written before the extension: `#legacy` is plain text and must be re-parsed.
            ['id' => 4, 'discussion_id' => 1, 'number' => 4, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t>Old #legacy</t>'],
        ]);
        $this->assertStringContainsString('<HASHTAG', $db->table('posts')->where('id', 1)->value('content'));

        // A row left over for a post that no longer says #stale.
        $db->table('hashtags')->insert(['id' => 99, 'name' => 'stale', 'name_key' => 'stale', 'post_count' => 1, 'discussion_count' => 1]);
        $db->table('post_hashtag')->insert(['post_id' => 3, 'hashtag_id' => 99, 'discussion_id' => 1]);

        $output = $this->runCommand(['command' => 'hashtags:reindex']);

        $this->assertSame(
            ['gameday' => 2, 'legacy' => 1, 'tailgate' => 1],
            $db->table('hashtags')->orderBy('name_key')->pluck('post_count', 'name_key')->map(fn ($n) => (int) $n)->all()
        );
        $this->assertSame(0, $db->table('post_hashtag')->where('post_id', 3)->count());
        $this->assertSame(4, $db->table('post_hashtag')->count());
        $this->assertStringContainsString('<HASHTAG', $db->table('posts')->where('id', 4)->value('content'));
        $this->assertStringContainsString('1 of 4 posts re-parsed, 4 posts indexed', $output);
    }
}
