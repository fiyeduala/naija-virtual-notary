<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which upload a sealed document was made from.
 *
 * A request can carry several documents — one primary and any number of
 * additional ones — and each is now sealed into its own PDF. Without this
 * column the finished files are an undifferentiated set: nothing says which
 * seal belongs to which upload, and re-sealing one of them cannot supersede
 * its own previous version without superseding all the others too.
 *
 * Null on every upload (they have no source) and on anything sealed before
 * this migration, which is correct: those were the only final document on
 * their request, so there was nothing to distinguish them from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_documents', function (Blueprint $table) {
            $table->foreignId('source_document_id')
                ->nullable()
                ->after('file_type')
                ->constrained('request_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('request_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_document_id');
        });
    }
};
