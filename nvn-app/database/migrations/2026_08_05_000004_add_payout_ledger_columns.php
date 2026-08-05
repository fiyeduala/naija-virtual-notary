<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns payouts into a ledger rather than a note.
 *
 * payments.payout_id is the whole mechanism: a fee counts towards what a notary
 * is owed only while it is unattached, and attaching it is what makes it paid.
 * Double payment therefore becomes impossible by construction rather than by
 * remembering to check a date range — and "what is in this payout?" is a query,
 * not an inference from period_start/period_end.
 *
 * A failed transfer detaches its payments so they fall back into the owed pile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payout_id')->nullable()->after('request_id')
                ->constrained('payouts')->nullOnDelete();
        });

        Schema::table('payouts', function (Blueprint $table) {
            // Our own reference, sent to Paystack as the transfer reference so
            // a webhook can be tied back to a row even before we store the code.
            $table->string('reference')->nullable()->unique()->after('id');
            // The platform's share of the same jobs. Kept so a payout row shows
            // the full picture of the money it settles, not just the notary half.
            $table->unsignedBigInteger('commission_amount')->default(0)->after('amount');
            $table->text('failure_reason')->nullable()->after('paystack_transfer_code');
            $table->foreignId('initiated_by')->nullable()->after('failure_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payout_id']);
            $table->dropColumn('payout_id');
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropForeign(['initiated_by']);
            $table->dropColumn(['reference', 'commission_amount', 'failure_reason', 'initiated_by']);
        });
    }
};
