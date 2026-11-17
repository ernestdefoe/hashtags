<?php

namespace Ernestdefoe\Hashtags\Search;

use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Restricts a hashtag query to what the actor can see, and replaces the
 * stored counts with counts of the posts the actor can see.
 *
 * The stored post_count, discussion_count and last_used_at count every use,
 * including posts in hidden, private or restricted-tag discussions. Serving
 * them, or listing a hashtag whose only use is somewhere the actor can't
 * look, told guests what members of a private area were talking about and
 * how much. So the list and every count come from one aggregate over the
 * pivot, joined to posts scoped by core's own post visibility (which covers
 * the discussion's visibility too, so tags, byobu and approval all apply).
 * The inner join drops a hashtag with no visible use.
 *
 * The syncer still writes the stored columns (from all use) and deletes a
 * hashtag whose pivot rows are gone; the API no longer serves them.
 */
class VisibleUsage
{
    public const ALIAS = 'hashtag_usage';

    /**
     * @param int[]|QueryBuilder|null $hashtagIds limit the aggregate to these
     *        hashtags (ids, or a query selecting them) when the caller has
     *        already narrowed the list
     */
    public static function apply(Builder $hashtags, User $actor, array|QueryBuilder|null $hashtagIds = null): void
    {
        $grammar = $hashtags->getQuery()->getGrammar();

        $usage = Post::query()
            ->whereVisibleTo($actor)
            ->join('post_hashtag', 'post_hashtag.post_id', '=', 'posts.id')
            ->when($hashtagIds !== null, fn ($query) => $query->whereIn('post_hashtag.hashtag_id', $hashtagIds))
            ->groupBy('post_hashtag.hashtag_id')
            ->select('post_hashtag.hashtag_id')
            // wrap() applies the table prefix, which selectRaw() would not.
            ->selectRaw('COUNT(*) as visible_post_count')
            ->selectRaw('COUNT(DISTINCT '.$grammar->wrap('post_hashtag.discussion_id').') as visible_discussion_count')
            ->selectRaw('MAX('.$grammar->wrap('posts.created_at').') as visible_last_used_at')
            ->toBase();

        $hashtags
            ->joinSub($usage, self::ALIAS, self::ALIAS.'.hashtag_id', '=', 'hashtags.id')
            ->select('hashtags.id', 'hashtags.name', 'hashtags.name_key', 'hashtags.created_at')
            /**
             * Selected under the stored columns' names, so the serializer and
             * the searcher's sorts (it orders by the bare `post_count`) both
             * use the visible counts. The derived table's own columns are
             * named differently on purpose: if both tables in FROM had a
             * post_count, SQLite would call ORDER BY post_count ambiguous
             * instead of taking the output column.
             */
            ->addSelect([
                self::ALIAS.'.visible_post_count as post_count',
                self::ALIAS.'.visible_discussion_count as discussion_count',
                self::ALIAS.'.visible_last_used_at as last_used_at',
            ]);
    }
}
