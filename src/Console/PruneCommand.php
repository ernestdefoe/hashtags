<?php

namespace Ernestdefoe\Hashtags\Console;

use Carbon\Carbon;
use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Ernestdefoe\Hashtags\Model\Hashtag;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Deletes unused hashtags first written more than 30 days ago.
 *
 * The sync never deletes a hashtag (that raced a concurrent sync writing the
 * same one), so an unused hashtag just stops being listed and its row waits
 * to be reused. Without this the table would only grow, and a spammer's
 * one-off tags would stay for ever. Scheduled daily in extend.php. The age
 * test spares hashtags that are brand new, nothing more.
 *
 * Racing a sync that is using one of these hashtags again at this moment:
 *  - The sync's row is in first: the foreign key on post_hashtag.hashtag_id
 *    RESTRICTS deletes (2026_11_19 migration), so the new row is never
 *    cascaded away. Depending on the database the DELETE waits and then
 *    skips that hashtag, or fails; a failure deletes nothing today and
 *    tomorrow's run goes again. A deadlock is retried once.
 *  - The DELETE is in first: the sync's insert fails its foreign key and
 *    HashtagSyncer runs once more, recreating the hashtag.
 */
class PruneCommand extends AbstractCommand
{
    protected const MIN_AGE_DAYS = 30;

    public function __construct(
        protected ConnectionInterface $db,
        protected LoggerInterface $logger
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hashtags:prune')
            ->setDescription('Delete hashtags no post uses that are more than 30 days old.');
    }

    protected function fire(): int
    {
        try {
            $deleted = $this->db->transaction(fn () => Hashtag::query()
                ->where('created_at', '<', Carbon::now()->subDays(self::MIN_AGE_DAYS))
                ->whereNotExists(fn (Builder $query) => $query
                    ->select('post_id')
                    ->from('post_hashtag')
                    ->whereColumn('post_hashtag.hashtag_id', 'hashtags.id'))
                ->delete(), 2);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), HashtagSyncer::INTEGRITY_VIOLATIONS, true)) {
                throw $e;
            }

            // See the class docblock: a hashtag was used again mid-delete.
            $this->info('A hashtag was used again while pruning; nothing deleted, the next run tries again.');
            $this->logger->info('hashtags:prune skipped: '.$e->getMessage());

            return 0;
        }

        $this->info("Deleted {$deleted} unused hashtags.");

        return 0;
    }
}
