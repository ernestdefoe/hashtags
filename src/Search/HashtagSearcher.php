<?php

namespace Ernestdefoe\Hashtags\Search;

use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterManager;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchResults;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backs `GET /api/hashtags` (autocomplete + the browse page).
 *
 * Scoped to the actor by VisibleUsage. This used to list every hashtag on the
 * grounds that a hashtag is a word, not content, but the word and its counts
 * are content: they said what a private area was talking about, and how much.
 */
class HashtagSearcher extends AbstractSearcher
{
    /** How long guests share one answer, in seconds. */
    protected const GUEST_TTL = 60;

    /**
     * The scope goes in as the FIRST mutator rather than in getQuery(), so it
     * runs after the filters: by then `filter[q]` has narrowed the hashtags,
     * and the visible-use aggregate only has to cover those. Autocomplete
     * asks on every keystroke, so it is the request that has to stay cheap.
     */
    public function __construct(FilterManager $filters, array $mutators, protected Repository $cache)
    {
        parent::__construct($filters, [fn (DatabaseSearchState $search) => $this->scope($search), ...$mutators]);
    }

    /**
     * Guests' unfiltered first pages are cached for a minute.
     *
     * The full list counts every visible use of every hashtag, which grows
     * with the forum, and the browse page is what guests and crawlers open.
     * Every guest sees the same posts, so one answer per sort and page size
     * serves them all; a post hidden in the meantime drops out within a
     * minute.
     *
     * Members are NOT cached: what a member sees depends on their groups,
     * tag permissions and private discussions, so no answer can be shared,
     * and a per-member key would only ever be hit by the same reader.
     * Filtered requests (autocomplete) and later pages are cheap or rare, and
     * caching them would let any guest fill the cache one keystroke at a time.
     */
    public function search(SearchCriteria $criteria): SearchResults
    {
        if (! $criteria->actor->isGuest() || $criteria->filters || $criteria->offset > 0) {
            return parent::search($criteria);
        }

        $key = 'ernestdefoe-hashtags.guest-list.'.md5(json_encode([$criteria->sort, $criteria->sortIsDefault, $criteria->limit]));

        [$hashtags, $areMoreResults] = $this->cache->remember($key, self::GUEST_TTL, function () use ($criteria) {
            $results = parent::search($criteria);

            return [$results->getResults()->all(), $results->areMoreResults()];
        });

        // The total is never read: HashtagResource::count() returns null.
        return new SearchResults(new Collection($hashtags), $areMoreResults, fn () => count($hashtags));
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
