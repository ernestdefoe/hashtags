<?php

namespace Ernestdefoe\Hashtags\Tests\unit;

use Ernestdefoe\Hashtags\Formatter\ConfigureHashtags;
use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Ernestdefoe\Hashtags\Model\Hashtag;
use PHPUnit\Framework\TestCase;

/**
 * What the syncer pulls out of a post's stored XML.
 *
 * This is the logic that failed silently once already: it was reading
 * `$post->content`, which HasFormattedContent UNPARSES on read, instead of
 * `$post->parsed_content`. It found no tags, raised no error, and the whole
 * feature did nothing — for as long as nobody looked.
 */
class HashtagExtractionTest extends TestCase
{
    private function xml(string ...$names): string
    {
        $tags = array_map(
            fn (string $n) => '<HASHTAG key="'.Hashtag::key($n).'" name="'.$n.'">#'.$n.'</HASHTAG>',
            $names
        );

        return '<r>text '.implode(' and ', $tags).' end</r>';
    }

    public function testPullsNamesKeyedByTheirFoldedForm(): void
    {
        $this->assertSame(
            ['gameday' => 'gameday', 'playbook' => 'playbook'],
            HashtagSyncer::namesFromXml($this->xml('gameday', 'playbook'))
        );
    }

    /**
     * One post writing both spellings has used ONE hashtag. Two rows would
     * violate the (post_id, hashtag_id) primary key and fail the insert.
     */
    public function testFoldsDifferentCasingsOfTheSameHashtagIntoOneEntry(): void
    {
        $names = HashtagSyncer::namesFromXml($this->xml('GameDay', 'gameday', 'GAMEDAY'));

        $this->assertCount(1, $names);
        $this->assertArrayHasKey('gameday', $names);
    }

    public function testTheFirstSpellingWinsAsTheDisplayName(): void
    {
        $names = HashtagSyncer::namesFromXml($this->xml('GameDay', 'gameday'));

        $this->assertSame('GameDay', $names['gameday']);
    }

    public function testFoldsNonAsciiCasingToo(): void
    {
        $names = HashtagSyncer::namesFromXml($this->xml('CAFÉ', 'café'));

        $this->assertCount(1, $names, 'mb_strtolower is required here; strtolower leaves É alone');
        $this->assertArrayHasKey('café', $names);
    }

    public function testIgnoresContentWithNoHashtags(): void
    {
        $this->assertSame([], HashtagSyncer::namesFromXml('<t>plain text, no tags</t>'));
    }

    public function testHandlesEmptyAndNullContent(): void
    {
        $this->assertSame([], HashtagSyncer::namesFromXml(null));
        $this->assertSame([], HashtagSyncer::namesFromXml(''));
    }

    /**
     * A post whose XML predates this extension has the hashtag sitting in a
     * plain text node. It must yield nothing — that state is what
     * `hashtags:reindex` exists to repair.
     */
    public function testUnparsedHashtagsInTextNodesYieldNothing(): void
    {
        $this->assertSame([], HashtagSyncer::namesFromXml('<t>an old post saying #gameday</t>'));
    }

    public function testTruncatesAnythingOverTheLengthLimit(): void
    {
        $long = str_repeat('a', ConfigureHashtags::MAX_LENGTH + 40);
        $names = HashtagSyncer::namesFromXml($this->xml($long));

        $this->assertSame(
            ConfigureHashtags::MAX_LENGTH,
            mb_strlen(reset($names)),
            'a name longer than the column can hold must be cut, not sent to the database'
        );
    }

    public function testKeyFoldsAndTrims(): void
    {
        $this->assertSame('gameday', Hashtag::key('  GameDay  '));
        $this->assertSame('日本語', Hashtag::key('日本語'));
        $this->assertSame('café', Hashtag::key('CAFÉ'));
    }
}
