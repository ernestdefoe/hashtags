<?php

namespace Ernestdefoe\Hashtags\Api\Resource;

use Ernestdefoe\Hashtags\Model\Hashtag;
use Ernestdefoe\Hashtags\Search\VisibleUsage;
use Ernestdefoe\Hashtags\Settings\PerPage;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;

/**
 * Read-only JSON:API resource for hashtags (`/api/hashtags`).
 *
 * Read-only on purpose: hashtags are freeform, so they come into being by
 * being written in a post, and once no post the reader can see uses one, it
 * is neither listed nor found (VisibleUsage). There is no create/update/delete
 * story — an admin "editing" a hashtag would silently disagree with the post
 * text that produced it.
 *
 * @extends AbstractDatabaseResource<Hashtag>
 */
class HashtagResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'hashtags';
    }

    public function model(): string
    {
        return Hashtag::class;
    }

    /**
     * The visibility scope (VisibleUsage) for every query built from this
     * resource. The list runs it in HashtagSearcher instead, after its
     * filters, so the aggregate only covers the hashtags that match; Show
     * runs it in find() below for the one hashtag it resolves.
     */
    public function scope(Builder $query, Context $context): void
    {
        VisibleUsage::apply($query, $context->getActor());
    }

    /**
     * Accept either the numeric id or the hashtag itself, so the feed page can
     * do `app.store.find('hashtags', 'gameday')` straight from the route
     * parameter without a lookup round-trip first.
     *
     * Folded through Hashtag::key() so /api/hashtags/GameDay and
     * /api/hashtags/gameday resolve to the same record.
     */
    public function find(string $id, Context $context): ?object
    {
        /**
         * 🚨 A name arrives PERCENT-ENCODED. Flarum's JSON:API layer hands the
         * raw path segment through without decoding it, so `#sıradışı` reached
         * here as `s%C4%B1rad%C4%B1%C5%9F%C4%B1` and matched nothing.
         *
         * ASCII hashtags hid this completely: they contain no characters that
         * need escaping, so the raw segment and the decoded one are the same
         * string. Only a non-ASCII hashtag shows it, and then only on this
         * lookup — the feed itself works, because `filter[hashtag]` travels in
         * the query string, which IS decoded. The page therefore listed the
         * right posts beside a "not found" alert. Reported by sonsuzus
         * 2026-10-29.
         *
         * rawurldecode is unambiguous here: the pattern that creates hashtags
         * allows only letters, numbers, marks, `-` and `_`, so a `%` can never
         * be part of a real name and always means an escape.
         */
        $hashtagId = Hashtag::query()
            ->when(
                // ctype_digit, not is_numeric: "1e3" or "0x1A" is a name, not an id.
                ctype_digit($id),
                fn (Builder $query) => $query->whereKey((int) $id),
                fn (Builder $query) => $query->where('name_key', Hashtag::key(rawurldecode($id)))
            )
            ->value('id');

        if ($hashtagId === null) {
            return null;
        }

        /**
         * The same visibility scope as the list (HashtagSearcher), counted for
         * this one hashtag only.
         */
        $query = Hashtag::query()->whereKey($hashtagId);

        VisibleUsage::apply($query, $context->getActor(), [(int) $hashtagId]);

        return $query->first();
    }

    /**
     * No `meta.page.total`. Counting visible hashtags re-runs the whole
     * visible-use aggregate (VisibleUsage) a second time, which doubled the
     * cost of the list, and nothing reads the total: the browse page shows
     * one page and autocomplete a handful. Pagination still gets a `next`
     * link from a full page.
     */
    public function count(object $query, Context $context): ?int
    {
        return null;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make(),
            Endpoint\Index::make()
                ->defaultSort('-postCount')
                /** Raised from core's 50 so the browse page's setting can reach PerPage::MAX. */
                ->paginate(20, PerPage::MAX),
        ];
    }

    public function fields(): array
    {
        return [
            /** Display casing, as first written. See the migration. */
            Schema\Str::make('name'),

            /** The case-folded form — this is what routes and filters use. */
            Schema\Str::make('nameKey')
                ->get(fn (Hashtag $hashtag) => $hashtag->name_key),

            Schema\Integer::make('postCount'),
            Schema\Integer::make('discussionCount'),

            Schema\DateTime::make('lastUsedAt')
                ->nullable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('postCount')
                ->descendingAlias('popular'),
            SortColumn::make('lastUsedAt')
                ->descendingAlias('recent'),
            SortColumn::make('name'),
        ];
    }
}
