<?php

namespace Ernestdefoe\Hashtags\Tests\unit;

use s9e\TextFormatter\Parser\Tag as FormatterTag;

/**
 * Stands in for flarum/mentions' addTagId filter.
 *
 * Must be a named static callable, not a closure: the Configurator serialises
 * the filter chain when it finalises, and a closure throws
 * "Serialization of 'Closure' is not allowed".
 *
 * Mirrors core's behaviour exactly, including the part the whole design rests
 * on — when the slug is not a real tag it leaves `id`/`tagname` unset rather
 * than invalidating, and the required-attribute check kills the tag for it.
 */
class TagMentionPrecedenceHelper
{
    public const KNOWN_TAGS = ['general' => 'General', 'speedrun' => 'Speedrun'];

    public static function addTagId(FormatterTag $tag): ?bool
    {
        $slug = (string) $tag->getAttribute('slug');

        if (isset(self::KNOWN_TAGS[$slug])) {
            $tag->setAttribute('id', '1');
            $tag->setAttribute('tagname', self::KNOWN_TAGS[$slug]);

            return true;
        }

        return null;
    }
}
