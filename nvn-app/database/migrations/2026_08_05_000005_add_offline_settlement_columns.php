<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that moves outside Paystack.
 *
 * A great deal of Nigerian business is still settled by direct bank transfer,
 * and the platform cannot pretend otherwise: a client may pay the company
 * account instead of the checkout page, and a payout may be sent from the bank
 * app rather than the Paystack balance. Both were previously invisible — the
 * only way to represent them was to lie in a status field.
 *
 * settlement_method is the whole distinction. NULL means it went through
 * Paystack in the ordinary way; anything else names how it was actually handled,
 * so "which of these did we do by hand?" stays a query rather than folklore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('settlement_method')->nullable()->after('status');
            $table->string('settlement_reference')->nullable()->after('settlement_method');
            $table->text('settlement_note')->nullable()->after('settlement_reference');
            // Who at the platform vouched for it. An offline payment has no
            // webhook behind it — a person is the evidence, so name them.
            $table->foreignId('recorded_by')->nullable()->after('settlement_note')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('settlement_method')->nullable()->after('status');
            $table->string('settlement_reference')->nullable()->after('settlement_method');
            $table->text('settlement_note')->nullable()->after('settlement_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['recorded_by']);
            $table->dropColumn(['settlement_method', 'settlement_reference', 'settlement_note', 'recorded_by']);
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['settlement_method', 'settlement_reference', 'settlement_note']);
        });
    }
};
