<?php

namespace Ernestdefoe\Hashtags\Settings;

/**
 * How many hashtags the /hashtags page asks for — an admin setting.
 *
 * MAX is also the Index endpoint's page[limit] cap (HashtagResource). The two
 * must move together: the JSON:API layer silently trims any larger limit to
 * its cap, which is how the old hard-coded 60 quietly showed 50.
 */
class PerPage
{
    public const KEY = 'ernestdefoe-hashtags.per_page';
    public const DEFAULT = 60;
    public const MAX = 100;

    /** Settings arrive as strings, or null when never saved. */
    public static function clamp(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT;
        }

        return max(1, min(self::MAX, (int) $value));
    }
}
