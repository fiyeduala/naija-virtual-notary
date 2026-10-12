<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has been reading the blog.
 *
 * One row per visit, where a visit is one person on one article on one day —
 * see the unique index at the bottom, which is what stops a reload counting as
 * a second reader.
 *
 * Deliberately NOT stored: the raw IP address and the full user agent. What is
 * needed here is "how many distinct people, and did they come back", and a
 * salted hash answers both without the table becoming a record of who read
 * what from where. The salt is derived from APP_KEY, so the hashes are not
 * reversible and not comparable across installations.
 *
 * `user_id` is the only column that can name anybody, and it is null for
 * almost every row: a marketing article's readers are overwhelmingly strangers
 * who have never made an account. That is a fact about blogs, not a gap here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            // Null for a visitor who is not signed in, which is most of them.
            // Null on delete rather than cascade: deleting an account should
            // not silently reduce an article's historical view count.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // sha256 hex of IP + user agent + an APP_KEY-derived salt.
            // char(64) and not text, because this is part of a UNIQUE index
            // and MySQL 8 rejects UNIQUE on a TEXT column outright (1170).
            $table->char('visitor_hash', 64);

            // Host only — "facebook.com", not the full URL with its query
            // string, which is where campaign ids and personal data live.
            $table->string('referrer_host', 120)->nullable();

            // Set by the beacon once the reader has actually read the thing;
            // null means they opened it and we never heard back. The gap
            // between a count of rows and a count of these is the whole point
            // of the table.
            $table->timestamp('read_at')->nullable();
            $table->unsignedInteger('seconds')->nullable();

            // The calendar day of the visit, carried as its own column purely
            // so it can sit in the unique index below. Derived from created_at
            // on write and never edited.
            $table->date('view_date');

            $table->timestamps();

            // One view per person per article per day. A unique index rather
            // than a check in PHP, because two tabs opening at once would pass
            // any check-then-insert and the count would drift upwards forever.
            $table->unique(['post_id', 'visitor_hash', 'view_date'], 'post_views_unique_daily');

            // The two reads this table exists for: one article's history, and
            // "what happened across the blog lately".
            $table->index(['post_id', 'view_date']);
            $table->index('view_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_views');
    }
};
