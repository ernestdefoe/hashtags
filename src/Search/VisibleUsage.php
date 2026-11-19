<?php

namespace Ernestdefoe\Hashtags\Search;

use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Restricts a hashtag query to what the actor can see, and adds the counts of
 * the posts the actor can see.
 *
 * Counting every use, or listing a hashtag whose only use is somewhere the
 * actor can't look, told guests what members of a private area were talking
 * about and how much. So the list and every count come from one aggregate
 * over the pivot, joined to posts scoped by core's own post visibility (which
 * covers the discussion's visibility too, so tags, byobu and approval all
 * apply). The inner join drops a hashtag with no visible use.
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
            // Aggregates have no builder method that takes an alias. wrap()
            // applies the table prefix, which selectRaw() would not.
            ->selectRaw(
                'COUNT(*) as post_count, COUNT(DISTINCT '.$grammar->wrap('post_hashtag.discussion_id').') as discussion_count, MAX('.$grammar->wrap('posts.created_at').') as last_used_at'
            )
            ->toBase();

        $hashtags
            ->joinSub($usage, self::ALIAS, self::ALIAS.'.hashtag_id', '=', 'hashtags.id')
            ->select('hashtags.id', 'hashtags.name', 'hashtags.name_key', 'hashtags.created_at')
            /**
             * The searcher's sorts order by the bare column name
             * (`post_count`), which resolves to these: `hashtags` has no
             * column of the same name to make it ambiguous.
             */
            ->addSelect([
                self::ALIAS.'.post_count',
                self::ALIAS.'.discussion_count',
                self::ALIAS.'.last_used_at',
            ]);
    }
}
