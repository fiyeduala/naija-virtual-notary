<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| What actually left the bank, when it is not what the payout is denominated in.
|
| A client abroad pays dollars; the notary has a Nigerian account and can only
| be sent naira. So a dollar payout records what was EARNED in dollars, and
| these two columns record what was SENT in naira — two different facts about
| the same settlement, and conflating them is how a notary ends up unable to
| reconcile their own payment.
|
| Null for every ordinary naira payout, where `amount` already is what was
| sent. The rate used is deliberately NOT stored: it is settled_amount divided
| by amount, exactly, and a stored copy is one more thing that can disagree
| with the figures it was derived from.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            // Minor units of `settled_currency` — kobo, when a dollar payout
            // is settled by a naira transfer.
            $table->unsignedBigInteger('settled_amount')->nullable()->after('commission_amount');
            $table->string('settled_currency', 3)->nullable()->after('settled_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['settled_amount', 'settled_currency']);
        });
    }
};
