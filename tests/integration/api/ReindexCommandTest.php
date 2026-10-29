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

    /**
     * Regression: reindex left behind the very posts it exists to repair.
     *
     * A post damaged by an older version holds a HASHTAG element that today's
     * rules would not produce — here `#p1233`, excluded in 2.0.7 because a
     * deleted post mention left it behind. Its text therefore no longer
     * matches the pattern, and the re-parse was gated on that match alone, so
     * the stray row was cleared while the element stayed in posts.content for
     * ever. One forum reported 1 post re-parsed out of 17,700 and ~1,050
     * replies left to fix by hand in SQL. Reported by sonsuzus 2026-10-29.
     */
    #[Test]
    public function it_reparses_posts_holding_a_hashtag_the_current_rules_would_not_produce(): void
    {
        $db = $this->database();

        $db->table('posts')->insert([
            [
                'id' => 10,
                'discussion_id' => 1,
                'number' => 10,
                'created_at' => Carbon::now(),
                'user_id' => 1,
                'type' => 'comment',
                // Exactly what 2.0.5 stored for a reply whose target was deleted.
                'content' => '<r><p>thanks @"kamildev"<HASHTAG key="p1233" name="p1233">#p1233</HASHTAG> for that</p></r>',
            ],
        ]);

        $id = $db->table('hashtags')->insertGetId([
            'name' => 'p1233', 'name_key' => 'p1233', 'post_count' => 1, 'discussion_count' => 1, 'created_at' => Carbon::now(),
        ]);
        $db->table('post_hashtag')->insert(['post_id' => 10, 'hashtag_id' => $id, 'discussion_id' => 1]);

        $this->runCommand(['command' => 'hashtags:reindex']);

        $content = (string) $db->table('posts')->where('id', 10)->value('content');

        $this->assertStringNotContainsString('<HASHTAG', $content, 'the stray element must be rewritten out of the post');
        $this->assertStringContainsString('#p1233', $content, 'the text itself must survive as plain text');
        $this->assertNull($db->table('hashtags')->where('name_key', 'p1233')->first(), 'the stray hashtag must leave the list');
    }
}
