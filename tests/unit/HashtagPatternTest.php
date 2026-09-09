<?php

namespace Ernestdefoe\Hashtags\Tests\unit;

use Ernestdefoe\Hashtags\Formatter\ConfigureHashtags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Parser;

/**
 * The behaviour the whole extension rests on: `#` is shared with
 * flarum/mentions' tag mentions, and position in the text decides the winner.
 *
 * Builds a real TextFormatter with core's tag-mention matcher AND ours, plus
 * Litedown and Autolink, so these are the actual parser rules rather than a
 * re-implementation of them. No Flarum boot and no database.
 */
class HashtagPatternTest extends TestCase
{
    /** Verbatim from flarum/mentions ConfigureMentions. */
    private const TAG_MENTION_REGEX = '/(?:[^“"]|^)\B#(?<slug>[-_\p{L}\p{N}\p{M}]+)\b/ui';

    private static ?Parser $parser = null;

    private function parser(): Parser
    {
        if (self::$parser !== null) {
            return self::$parser;
        }

        $config = new Configurator;
        $config->rootRules->enableAutoLineBreaks();
        $config->Litedown;
        $config->Autolink;

        // ---- what flarum/mentions registers ----
        $mention = $config->tags->add('TAGMENTION');
        $mention->attributes->add('slug');
        $mention->attributes->add('tagname');
        $mention->attributes->add('id')->filterChain->append('#uint');
        $mention->template = '<a class="TagMention"><xsl:value-of select="@tagname"/></a>';
        $mention->filterChain->prepend([TagMentionPrecedenceHelper::class, 'addTagId']);
        $config->Preg->match(self::TAG_MENTION_REGEX, 'TAGMENTION');

        // ---- what we register ----
        (new ConfigureHashtags(new UrlGeneratorStub))($config);

        return self::$parser = $config->finalize()['parser'];
    }

    private function parse(string $text): string
    {
        return $this->parser()->parse($text);
    }

    public function testARealTagSlugStaysATagMention(): void
    {
        $xml = $this->parse('see #general please');

        $this->assertStringContainsString('<TAGMENTION', $xml);
        $this->assertStringNotContainsString('<HASHTAG', $xml);
    }

    public function testAnUnknownSlugFallsThroughToAHashtag(): void
    {
        $xml = $this->parse('see #foryourpage please');

        $this->assertStringContainsString('<HASHTAG', $xml);
        $this->assertStringNotContainsString('<TAGMENTION', $xml);
    }

    public function testBothKindsCoexistInOneSentence(): void
    {
        $xml = $this->parse('posting #foryourpage in #general now');

        $this->assertStringContainsString('<HASHTAG', $xml);
        $this->assertStringContainsString('<TAGMENTION', $xml);
    }

    /**
     * Precedence must not depend on which extension registered first. Both
     * matchers are added with the Preg plugin's hardcoded sortPriority of
     * -100, so if this ever starts depending on order it will do so silently.
     */
    public function testPrecedenceHoldsRegardlessOfRegistrationOrder(): void
    {
        $config = new Configurator;
        $config->Litedown;
        $config->Autolink;

        (new ConfigureHashtags(new UrlGeneratorStub))($config);

        $mention = $config->tags->add('TAGMENTION');
        $mention->attributes->add('slug');
        $mention->attributes->add('tagname');
        $mention->attributes->add('id')->filterChain->append('#uint');
        $mention->template = '<a class="TagMention"><xsl:value-of select="@tagname"/></a>';
        $mention->filterChain->prepend([TagMentionPrecedenceHelper::class, 'addTagId']);
        $config->Preg->match(self::TAG_MENTION_REGEX, 'TAGMENTION');

        $parser = $config->finalize()['parser'];

        $this->assertStringContainsString('<TAGMENTION', $parser->parse('see #general please'));
        $this->assertStringContainsString('<HASHTAG', $parser->parse('see #foryourpage please'));
    }

    #[DataProvider('shouldMatch')]
    public function testMatches(string $text, string $expectedName): void
    {
        $xml = $this->parse($text);

        $this->assertStringContainsString('<HASHTAG', $xml, "no hashtag in: $xml");
        $this->assertStringContainsString('name="'.$expectedName.'"', $xml, "wrong name in: $xml");
    }

    public static function shouldMatch(): array
    {
        return [
            'plain' => ['a #gameday b', 'gameday'],
            'casing preserved' => ['a #GameDay b', 'GameDay'],
            'hyphens' => ['a #multi-word-thing b', 'multi-word-thing'],
            'underscores' => ['a #two_words b', 'two_words'],
            'digits after a letter' => ['a #abc123 b', 'abc123'],
            'digits before a letter' => ['a #123abc b', '123abc'],
            'accented' => ['a #café b', 'café'],
            'non-latin' => ['a #日本語 b', '日本語'],
            'start of input' => ['#gameday leads', 'gameday'],
            'trailing punctuation' => ['a #gameday. b', 'gameday'],
            'at the length limit' => ['a #'.str_repeat('a', ConfigureHashtags::MAX_LENGTH).' b', str_repeat('a', ConfigureHashtags::MAX_LENGTH)],
        ];
    }

    #[DataProvider('shouldNotMatch')]
    public function testDoesNotMatch(string $text): void
    {
        $this->assertStringNotContainsString('<HASHTAG', $this->parse($text), "unexpected hashtag from: $text");
    }

    public static function shouldNotMatch(): array
    {
        return [
            'C sharp' => ['I write C# daily'],
            'F sharp' => ['F# is a language'],
            'mid-word' => ['a#gameday is not one'],
            'digits only' => ['issue #42 here'],
            'markdown heading' => ["# Heading\n\ntext"],
            'inline code' => ['`#gameday` in code'],
            'fenced code' => ["```\n#gameday\n```"],
            'indented code' => ['    #gameday'],
            'url fragment' => ['https://example.com/page#gameday'],
            'markdown link' => ['[x](https://example.com/a#gameday)'],
            'bare hash' => ['a # alone'],
            'over the length limit' => ['a #'.str_repeat('a', ConfigureHashtags::MAX_LENGTH + 20).' b'],
        ];
    }

    public function testTheCanonicalKeyIsFoldedWhileTheDisplayNameIsNot(): void
    {
        $xml = $this->parse('a #GameDay b');

        $this->assertStringContainsString('name="GameDay"', $xml);
        $this->assertStringContainsString('key="gameday"', $xml);
    }
}
