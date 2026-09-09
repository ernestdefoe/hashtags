<?php

namespace Ernestdefoe\Hashtags\Api\Resource;

use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Tobyz\JsonApiServer\Context;

/**
 * Read-only JSON:API resource for hashtags (`/api/hashtags`).
 *
 * Read-only on purpose: hashtags are freeform, so they come into being by
 * being written in a post and cease to exist when the last post using them is
 * edited or deleted (HashtagSyncer). There is no create/update/delete story —
 * an admin "editing" a hashtag would silently disagree with the post text that
 * produced it.
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
     * Accept either the numeric id or the hashtag itself, so the feed page can
     * do `app.store.find('hashtags', 'gameday')` straight from the route
     * parameter without a lookup round-trip first.
     *
     * Folded through Hashtag::key() so /api/hashtags/GameDay and
     * /api/hashtags/gameday resolve to the same record.
     */
    public function find(string $id, Context $context): ?object
    {
        if (is_numeric($id)) {
            return $this->query($context)->find($id);
        }

        return $this->query($context)->where('name_key', Hashtag::key($id))->first();
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make(),
            Endpoint\Index::make()
                ->defaultSort('-postCount')
                ->paginate(),
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
