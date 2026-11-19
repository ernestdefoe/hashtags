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
    public const MAX = 100;

    /**
     * Keeps a saved value inside 1..MAX. Never-saved, blank and non-numeric
     * values never get here as such: the default registered in extend.php
     * covers the first, and resetWhen() deletes the others on save.
     */
    public static function clamp(int|string $value): int
    {
        return max(1, min(self::MAX, (int) $value));
    }
}
