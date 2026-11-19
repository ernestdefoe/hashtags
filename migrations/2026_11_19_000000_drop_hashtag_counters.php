<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Brings installs from before 2.2 in line with the create migrations.
 *
 *  - The stored counters go: since 2.1.1 the API counts the posts the reader
 *    can see, so post_count, discussion_count and last_used_at were written
 *    on every save and never read.
 *  - The two remaining indexes get Laravel's generated names. They were
 *    hand-named, and Laravel does not prefix a name it is given, so two
 *    installs sharing one database (tables `a_hashtags` and `b_hashtags`)
 *    collided on the index name. Without a table prefix the unique index's
 *    generated name is the old name, so it is left alone.
 *
 *  - post_hashtag.hashtag_id stops cascading deletes and restricts them, so
 *    hashtags:prune can never delete a hashtag a post has just started using
 *    (see PruneCommand).
 *
 * Every step checks first, so a fresh install (created without them) passes
 * straight through.
 */
/** Re-creates post_hashtag.hashtag_id's foreign key with this ON DELETE, if it differs. */
$setHashtagDelete = function (Builder $schema, string $onDelete): void {
    foreach ($schema->getForeignKeys('post_hashtag') as $key) {
        if ($key['columns'] === ['hashtag_id'] && strtolower((string) $key['on_delete']) !== $onDelete) {
            // By column, not name: SQLite can only drop a foreign key that way.
            // The key was never hand-named, so the generated name matches.
            $schema->table('post_hashtag', function (Blueprint $table) use ($onDelete) {
                $table->dropForeign(['hashtag_id']);
                $table->foreign('hashtag_id')->references('id')->on('hashtags')->onDelete($onDelete);
            });
        }
    }
};

return [
    'up' => function (Builder $schema) use ($setHashtagDelete) {
        $prefixed = $schema->getConnection()->getTablePrefix() !== '';

        $schema->table('hashtags', function (Blueprint $table) use ($schema, $prefixed) {
            // By columns, not name: hand-named before 2.2, generated after a down().
            foreach ($schema->getIndexes('hashtags') as $index) {
                if ($index['columns'] === ['post_count', 'last_used_at']) {
                    $table->dropIndex($index['name']);
                }
            }

            if ($prefixed && $schema->hasIndex('hashtags', 'hashtags_name_key_unique')) {
                $table->dropUnique('hashtags_name_key_unique');
                $table->unique('name_key');
            }
        });

        $counters = array_values(array_filter(
            ['post_count', 'discussion_count', 'last_used_at'],
            fn (string $column) => $schema->hasColumn('hashtags', $column)
        ));

        if ($counters) {
            $schema->table('hashtags', fn (Blueprint $table) => $table->dropColumn($counters));
        }

        // A hashtag in use can no longer be deleted (see PruneCommand).
        $setHashtagDelete($schema, 'restrict');

        /**
         * Added before the old one is dropped: on MySQL the hashtag_id foreign
         * key needs an index that starts with hashtag_id at every moment.
         */
        if ($schema->hasIndex('post_hashtag', 'post_hashtag_feed_idx')) {
            $schema->table('post_hashtag', function (Blueprint $table) {
                $table->index(['hashtag_id', 'discussion_id']);
                $table->dropIndex('post_hashtag_feed_idx');
            });
        }
    },

    /**
     * Puts the cascade back, and the counter columns and their index (empty) so 2.1.x code,
     * which writes them, runs again; its next sync of each hashtag refills
     * them. The other indexes keep their generated names: no version reads
     * an index by name, and restoring the hand-made ones would bring back
     * the cross-install collision.
     */
    'down' => function (Builder $schema) use ($setHashtagDelete) {
        $setHashtagDelete($schema, 'cascade');

        if ($schema->hasColumn('hashtags', 'post_count')) {
            return;
        }

        $schema->table('hashtags', function (Blueprint $table) {
            $table->unsignedInteger('post_count')->default(0);
            $table->unsignedInteger('discussion_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->index(['post_count', 'last_used_at']);
        });
    },
];
