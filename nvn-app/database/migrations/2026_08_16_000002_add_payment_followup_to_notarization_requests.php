<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that somebody has already chased a client about an unpaid request.
 *
 * Deliberately not a schedule: unlike the notary asset reminders, this is a
 * conversation an admin chooses to start, because "why has this not been paid"
 * has answers — the price is wrong, the card failed, they changed their mind —
 * that want a person reading the reply, not a cron job sending a third copy.
 *
 * What these columns buy is the memory: who has already been written to, when,
 * and how many times, so a second admin does not chase the same client again an
 * hour later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->timestamp('payment_followed_up_at')->nullable()->after('submitted_at');
            $table->unsignedTinyInteger('payment_followups_sent')->default(0)->after('payment_followed_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_followed_up_at', 'payment_followups_sent']);
        });
    }
};
