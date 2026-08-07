<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The blog, carried over from the WordPress site this replaces.
 *
 * `body` holds HTML, which is the one genuinely dangerous column in this
 * application: it is written by an admin and rendered unescaped to the public.
 * It is sanitised on the way in by the Post model, never rendered through
 * Blade, and nobody but an admin can write to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();

            // Nullable, and null on delete: removing a staff account should not
            // silently delete the articles they wrote.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('cover_image')->nullable();      // path on the 'blog' disk
            $table->string('meta_description', 300)->nullable();

            $table->enum('status', ['draft', 'published'])->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();

            // Where it came from, so a re-run of the WordPress import updates
            // the article instead of publishing a second copy of it.
            $table->string('legacy_source', 40)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->index(['legacy_source', 'legacy_id']);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
