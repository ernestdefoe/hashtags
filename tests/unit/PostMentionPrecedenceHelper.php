<?php

namespace Ernestdefoe\Hashtags\Tests\unit;

use s9e\TextFormatter\Parser\Tag as FormatterTag;

/**
 * Stands in for flarum/mentions' addPostId and addGroupId.
 *
 * The behaviour that matters is the failure path: when the target is gone,
 * core returns null WITHOUT invalidating and WITHOUT setting the attributes
 * the tag declares as required. filterAttributes then invalidates the tag,
 * which does not advance the parser cursor — and that is what used to let
 * HASHTAG claim the `#p123`.
 *
 * Named static callables, not closures: the Configurator serialises the
 * filter chain when it finalises.
 */
class PostMentionPrecedenceHelper
{
    /** Ids still present. Emptying this is how a test deletes the target. */
    public static array $livePosts = [1233];
    public static array $liveGroups = [45];

    public static function addPostId(FormatterTag $tag): ?bool
    {
        if (in_array((int) $tag->getAttribute('id'), self::$livePosts, true)) {
            $tag->setAttribute('discussionid', '7');
            $tag->setAttribute('number', '3');

            return true;
        }

        return null;
    }

    public static function addGroupId(FormatterTag $tag): ?bool
    {
        if (in_array((int) $tag->getAttribute('id'), self::$liveGroups, true)) {
            $tag->setAttribute('groupname', 'Moderators');

            return true;
        }

        return null;
    }
}
