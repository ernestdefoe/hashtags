<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Which posts use which hashtags.
 *
 * `discussion_id` is denormalised off the post so the feed query
 * (HashtagFilter) can resolve "discussions using #x" with a single indexed
 * subquery instead of joining through posts on every search.
 */
return Migration::createTableIfNotExists('post_hashtag', function (Blueprint $table) {
    $table->unsignedInteger('post_id');
    $table->unsignedInteger('hashtag_id');
    $table->unsignedInteger('discussion_id');

    /**
     * A post uses a hashtag once, however many times it writes it. Making the
     * pair the primary key gives us that for free and keeps the row narrow.
     */
    $table->primary(['post_id', 'hashtag_id']);

    /** Feed lookup: every discussion using this hashtag. */
    $table->index(['hashtag_id', 'discussion_id'], 'post_hashtag_feed_idx');

    /**
     * Hard-deleting a post or discussion removes its pivot rows. The counters
     * on `hashtags` are NOT corrected by the cascade — they are recomputed on
     * the next save touching that hashtag, and the feed reads through the
     * pivot, so a stale counter is cosmetic and self-heals.
     */
    $table->foreign('post_id')
          ->references('id')->on('posts')
          ->cascadeOnDelete();

    $table->foreign('hashtag_id')
          ->references('id')->on('hashtags')
          ->cascadeOnDelete();

    $table->foreign('discussion_id')
          ->references('id')->on('discussions')
          ->cascadeOnDelete();
});
