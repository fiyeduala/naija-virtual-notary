<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HTTP session storage for SESSION_DRIVER=database.
 *
 * Deliberately NOT called `sessions`: this application already owns a table of
 * that name, and it means something else entirely — the video verification
 * session for a notarization (2025_01_01_000006). Pointing Laravel's session
 * handler at it makes every request try to write a `payload` column onto a
 * notarization record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('http_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('http_sessions');
    }
};
