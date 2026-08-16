<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the platform chase an approved notary who never uploaded their marks.
 *
 * The approval email already asks for them, but it is one email, and a notary
 * who misses it is approved, listed nowhere, and unable to take a booking with
 * nothing to tell them so. These two columns are what stop the chase becoming
 * a daily nag: when they last heard from us, and how many times in total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            $table->timestamp('assets_reminded_at')->nullable()->after('approved_by');
            $table->unsignedTinyInteger('assets_reminders_sent')->default(0)->after('assets_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            $table->dropColumn(['assets_reminded_at', 'assets_reminders_sent']);
        });
    }
};
