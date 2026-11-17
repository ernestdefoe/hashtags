<?php

namespace Ernestdefoe\Hashtags\Search;

use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterManager;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backs `GET /api/hashtags` (autocomplete + the browse page).
 *
 * Scoped to the actor by VisibleUsage. This used to list every hashtag on the
 * grounds that a hashtag is a word, not content, but the word and its counts
 * are content: they said what a private area was talking about, and how much.
 */
class HashtagSearcher extends AbstractSearcher
{
    /**
     * The scope goes in as the FIRST mutator rather than in getQuery(), so it
     * runs after the filters: by then `filter[q]` has narrowed the hashtags,
     * and the visible-use aggregate only has to cover those. Autocomplete
     * asks on every keystroke, so it is the request that has to stay cheap.
     */
    public function __construct(FilterManager $filters, array $mutators)
    {
        parent::__construct($filters, [fn (DatabaseSearchState $search) => $this->scope($search), ...$mutators]);
    }

    public function getQuery(User $actor): Builder
    {
        return Hashtag::query();
    }

    protected function scope(DatabaseSearchState $search): void
    {
        $query = $search->getQuery();

        $narrowed = $query->getQuery()->wheres
            ? (clone $query)->toBase()->cloneWithout(['orders', 'limit', 'offset'])->select('hashtags.id')
            : null;

        VisibleUsage::apply($query, $search->getActor(), $narrowed);
    }
}
