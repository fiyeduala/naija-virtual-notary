<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The response window stopped reassigning requests and became an SLA clock.
 *
 * ProcessFallbacks now alerts the admin when the window elapses instead of
 * taking the job off the partner notary. That alert must fire once, not on
 * every cron tick, so the moment it was sent is recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->timestamp('fallback_alerted_at')->nullable()->after('fallback_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->dropColumn('fallback_alerted_at');
        });
    }
};
