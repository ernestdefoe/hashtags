<?php

namespace Ernestdefoe\Hashtags\Formatter;

use Flarum\Http\UrlGenerator;
use s9e\TextFormatter\Configurator;

/**
 * Registers the HASHTAG tag with the s9e/TextFormatter pipeline.
 *
 * ── Why this coexists with flarum/tags' `#slug` mentions ──────────────────
 *
 * flarum/mentions already claims `#` for tag mentions. It does NOT need to be
 * patched, disabled or reordered, because of how s9e resolves two matchers
 * that want the same text:
 *
 *   1. Core's TAG_MENTION_WITH_SLUG_REGEX opens with `(?:[^“"]|^)` — it
 *      consumes the character BEFORE the `#`. So its tag starts one byte
 *      earlier in the text than ours does.
 *   2. s9e sorts pending tags by position first (Parser::getSortKey), so
 *      TAGMENTION is always offered the text before HASHTAG is.
 *   3. If the slug resolves to a real tag, TAGMENTION is applied and the
 *      parser cursor advances past `#slug`. Our HASHTAG tag then sits behind
 *      the cursor and is discarded — the tag mention wins, as it should.
 *   4. If the slug is NOT a tag, core's addTagId leaves `id`/`tagname` unset.
 *      Both are required attributes, so filterAttributes invalidates the tag.
 *      An invalidated tag does not advance the cursor, so HASHTAG is offered
 *      the same text next and claims it.
 *
 * The upshot: `#general` links to the tag when a tag with that slug exists,
 * and `#foryourpage` becomes a freeform hashtag. Precedence is POSITIONAL, so
 * it does not depend on extension load order or on sortPriority (the Preg
 * plugin hardcodes -100 for every matcher it registers, so priority is not
 * available to us as a lever anyway).
 *
 * Do not "simplify" the regex by giving it a leading-character prefix to match
 * core's shape. That would put both tags at the same position, and the winner
 * would then be decided by match length and registration order — which is not
 * stable across installs.
 *
 * ── The pattern ──────────────────────────────────────────────────────────
 *
 *   \B#      `#` not preceded by a word character. This is what makes `C#`,
 *            `F#` and `foo#bar` safe, and it mirrors the shape core uses
 *            without needing a lookbehind (which s9e's JS regexp convertor
 *            cannot emit).
 *
 *   {0,29}\p{L}{0,29}
 *            At least one letter, so `#123` is left alone for
 *            ernestdefoe/cross-references (`\B#\d+`) and for anyone typing an
 *            issue number. Bounded at 59 characters: a longer run of word
 *            characters has no `\b` to close on, so it simply is not a
 *            hashtag rather than becoming a 400-character link.
 *
 *   \p{N}\p{M} + `-_`
 *            Unicode-safe: `#café`, `#日本語` and `#multi-word` all work.
 *
 * Verified against Litedown and Autolink: hashtags are NOT parsed inside code
 * spans, fenced blocks or indented blocks, and a `#fragment` inside a URL is
 * protected because the URL tag starts earlier and runs longer.
 */
class ConfigureHashtags
{
    public const TAG = 'HASHTAG';

    /**
     * Longest hashtag we will match, in characters. Mirrored in the JS
     * mentionable's query pattern and in HashtagSyncer::MAX_LENGTH — change
     * all three together, and clear the formatter cache afterwards.
     */
    public const MAX_LENGTH = 59;

    public const REGEX = '/\B#(?<name>[-_\p{L}\p{N}\p{M}]{0,29}\p{L}[-_\p{L}\p{N}\p{M}]{0,29})\b/ui';

    public function __construct(protected UrlGenerator $url) {}

    public function __invoke(Configurator $config): void
    {
        $tag = $config->tags->add(self::TAG);

        $tag->attributes->add('name');

        /**
         * The hashtag feed route prefix, captured once at compile time. If the
         * forum URL changes the formatter cache must be cleared — same caveat
         * as core's own $TAG_URL and $PROFILE_URL parameters.
         */
        $config->rendering->parameters['HASHTAG_URL'] =
            $this->url->to('forum')->route('hashtag', ['name' => '']);

        /**
         * The link text re-emits `#` plus the name EXACTLY as it was typed, so
         * `#GameDay` renders as `#GameDay` even though it resolves to the same
         * feed as `#gameday`. Casing is the author's; matching is not.
         */
        $tag->template = '
            <a class="Hashtag" href="{$HASHTAG_URL}{@name}" data-hashtag="{@name}">
                <xsl:text>#</xsl:text><xsl:value-of select="@name"/>
            </a>';

        $config->Preg->match(self::REGEX, self::TAG);
    }
}
