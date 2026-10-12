<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments on a blog article.
 *
 * `body` is PLAIN TEXT and must stay plain text. Post::body is the one column
 * in this application rendered unescaped to the public, and it is only safe
 * because an admin writes it and HtmlSanitizer runs on every write. A comment
 * is written by a stranger, so it is escaped on the way out like any other
 * string and never passes through the sanitiser, which exists to ALLOW some
 * tags through and is therefore exactly the wrong tool here.
 *
 * `author_email` is collected so you can reach somebody about what they wrote,
 * and is never rendered on a public page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            // Set when the commenter was signed in. Their name and email are
            // still copied onto the row below, so an account closed later does
            // not blank out a comment that is already published.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // One level of threading, which is enough for "you reply to a
            // reader". Cascade, so deleting a comment takes its replies with
            // it rather than leaving them orphaned under nothing.
            $table->foreignId('parent_id')->nullable()->constrained('post_comments')->cascadeOnDelete();

            $table->string('author_name', 120);
            $table->string('author_email', 190);
            $table->text('body');

            // A string and not an enum, on purpose. A value outside a MySQL
            // enum is truncated with warning 1265 and saves fine on SQLite, so
            // an enum is a difference between the two engines waiting to
            // happen — we have already paid for that once. The allowed values
            // live on PostComment::STATUSES and are enforced in the app.
            $table->string('status', 20)->default('pending')->index();

            // Same salted hash as post_views, for rate-limiting and for
            // spotting one person posting under six names. Not an IP.
            $table->char('visitor_hash', 64)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The public read: this article's approved comments, oldest first.
            $table->index(['post_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
    }
};
