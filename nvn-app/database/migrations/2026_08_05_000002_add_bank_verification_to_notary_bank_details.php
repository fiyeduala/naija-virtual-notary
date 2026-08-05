<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paystack transfers address an account by BANK CODE, not by bank name — the
 * free-text "bank_name" the form used to collect cannot be paid to.
 *
 * resolved_account_name is what the bank itself returned for the account, as
 * opposed to account_name, which is what the notary typed. When the two
 * disagree, somebody has made a mistake worth catching before money moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notary_bank_details', function (Blueprint $table) {
            $table->string('bank_code', 10)->nullable()->after('bank_name');
            $table->string('resolved_account_name')->nullable()->after('account_name');
            $table->timestamp('verified_at')->nullable()->after('paystack_recipient_code');
            // Null = never checked. False = the bank's name for this account does
            // not look like the notary's; payable, but an admin should look.
            $table->boolean('name_matches')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('notary_bank_details', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'resolved_account_name', 'verified_at', 'name_matches']);
        });
    }
};
