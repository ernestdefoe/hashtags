<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per distinct hashtag, keyed on a case-folded `name_key`.
 *
 * `name` keeps the casing of the FIRST post that used it, purely for display
 * in the autocomplete dropdown and the index page. Matching, routing and
 * deduplication all go through `name_key`, so `#GameDay` and `#gameday` are
 * one hashtag with one feed.
 */
return Migration::createTableIfNotExists('hashtags', function (Blueprint $table) {
    /**
     * INT (not BIGINT) to match Flarum core's `increments('id')` on posts and
     * discussions. The post_hashtag pivot carries FKs to all three, and MySQL
     * requires exact size/sign match on both sides of a constraint — widening
     * here would break the pivot's FK creation.
     */
    $table->increments('id');

    /**
     * 60 chars covers ConfigureHashtags::MAX_LENGTH (59) with a byte to spare.
     * In utf8mb4 the unique index on name_key is 240 bytes, well inside
     * InnoDB's 3072-byte limit, so no prefix index is needed.
     */
    $table->string('name', 60);
    $table->string('name_key', 60)->unique();

    /**
     * No stored counts: they depend on who is reading, so the API counts the
     * posts the reader can see (VisibleUsage). Installs from before 2.2 had
     * counter columns and hand-named indexes; the 2026_11_19 migration
     * removes and renames them.
     */
    $table->timestamp('created_at')->useCurrent();
});
