<?php

namespace Ernestdefoe\Hashtags\Search\Filter;

use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Post\Post;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;
use Illuminate\Database\Eloquent\Builder;

/**
 * `hashtag:gameday` → discussions with at least one post, visible to the actor, using that hashtag.
 * `-hashtag:gameday` excludes them.
 *
 * This is what the /hashtag/:name feed page runs, which means the feed inherits
 * Flarum's own discussion visibility scoping, pagination and sort options for
 * free rather than reimplementing them in a bespoke controller. A hashtag used
 * only inside a discussion the actor cannot see yields an empty feed.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class HashtagFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'hashtag';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $key = Hashtag::key($this->asString($value));

        if ($key === '') {
            return;
        }

        $method = $negate ? 'whereNotIn' : 'whereIn';

        /** @var Builder<\Flarum\Discussion\Discussion> $query */
        $query = $state->getQuery();

        /**
         * Resolved as a subquery rather than a join, so a hashtag used by 50k
         * posts does not multiply the discussion rows.
         *
         * The posts must be ones the actor can see, not just the discussion:
         * otherwise a private or unapproved post using #x put its (visible)
         * discussion in the #x feed, telling readers what the hidden post said.
         */
        $usedIn = Post::query()
            ->whereVisibleTo($state->getActor())
            ->join('post_hashtag', 'post_hashtag.post_id', '=', 'posts.id')
            ->join('hashtags', 'hashtags.id', '=', 'post_hashtag.hashtag_id')
            ->where('hashtags.name_key', $key)
            ->select('post_hashtag.discussion_id')
            ->toBase();

        $query->{$method}('discussions.id', $usedIn);
    }
}
