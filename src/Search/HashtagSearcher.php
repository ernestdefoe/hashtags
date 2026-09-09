<?php

namespace Ernestdefoe\Hashtags\Search;

use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backs `GET /api/hashtags` (autocomplete + the browse page).
 *
 * No whereVisibleTo: a hashtag row is a word, not content. What a hashtag
 * leads to is scoped per-actor by the discussion search itself (see
 * HashtagFilter), so a hashtag only used in a private discussion appears in
 * the list but its feed comes back empty for anyone who cannot see it.
 *
 * That is a deliberate trade: it leaks the existence of a word, not of a
 * discussion, a title, or an author.
 */
class HashtagSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Hashtag::query()->select('hashtags.*');
    }
}
