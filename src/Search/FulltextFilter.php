<?php

namespace Ernestdefoe\Hashtags\Search;

use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;

/**
 * `filter[q]` on the hashtags resource — a prefix match, which is what an
 * autocomplete wants: typing "gam" should offer #gameday, not everything
 * containing "gam" somewhere in the middle.
 *
 * Matched against name_key (already case-folded at write time) with the typed
 * value folded the same way, so "GAM" and "gam" behave identically without
 * needing a case-insensitive collation.
 *
 * @extends AbstractFulltextFilter<DatabaseSearchState>
 */
class FulltextFilter extends AbstractFulltextFilter
{
    public function search(SearchState $state, string $value): void
    {
        $value = Hashtag::key($value);

        if ($value === '') {
            return;
        }

        // Escape LIKE wildcards in user input: without this, a query of "%"
        // matches every hashtag and "_" matches any single character.
        $escaped = addcslashes($value, '%_\\');

        $state->getQuery()->where('name_key', 'like', "$escaped%");
    }
}
