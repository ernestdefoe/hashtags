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
 * Every step checks first, so a fresh install (created without them) passes
 * straight through.
 */
return [
    'up' => function (Builder $schema) {
        $prefixed = $schema->getConnection()->getTablePrefix() !== '';

        $schema->table('hashtags', function (Blueprint $table) use ($schema, $prefixed) {
            if ($schema->hasIndex('hashtags', 'hashtags_popularity_idx')) {
                $table->dropIndex('hashtags_popularity_idx');
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
     * Puts the counter columns back (empty) so 2.1.x code, which writes them,
     * runs again; its next sync of each hashtag refills them. The generated
     * index names stay: they work for every version.
     */
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('hashtags', 'post_count')) {
            return;
        }

        $schema->table('hashtags', function (Blueprint $table) {
            $table->unsignedInteger('post_count')->default(0);
            $table->unsignedInteger('discussion_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
        });
    },
];
