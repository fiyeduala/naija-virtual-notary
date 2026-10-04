<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which body sent this work, and what it earns on it.
 *
 * organization_commission_rate is frozen onto the request, not read from the
 * organization when a payout runs. The reason is the same one that froze
 * unit_fee_minor: an admin renegotiating a rate tomorrow must not change what
 * a body earns on a job that completed last month.
 *
 * payments.organization_payout_id is a SECOND, INDEPENDENT ledger column.
 * payments.payout_id is untouched and Payment::scopePayable() is not edited —
 * a fee can be claimed by a notary payout and by an organization payout
 * without either run seeing the other's claim, because they are separate
 * questions about the same money. (In practice organization work is notarized
 * by the platform's own profile, which generates no notary payout at all; the
 * columns are kept apart so that fact stays an observation rather than a
 * load-bearing assumption.)
 *
 * No new value on users.role. That column is a MySQL enum and a fourth value
 * needs raw per-driver SQL that the SQLite test suite would reject, so the
 * portal signs in through its own guard instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('notary_id')
                ->constrained('organizations')->nullOnDelete();
            $table->unsignedTinyInteger('organization_commission_rate')->nullable()
                ->after('organization_id');
            $table->timestamp('organization_referred_at')->nullable()
                ->after('organization_commission_rate');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('organization_payout_id')->nullable()->after('payout_id')
                ->constrained('organization_payouts')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // First touch, never overwritten: the body that introduced this
            // person to the platform, which is not necessarily the one whose
            // link they happened to click last.
            $table->foreignId('referred_by_organization_id')->nullable()->after('status')
                ->constrained('organizations')->nullOnDelete();
            $table->timestamp('organization_referred_at')->nullable()
                ->after('referred_by_organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by_organization_id']);
            $table->dropColumn(['referred_by_organization_id', 'organization_referred_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['organization_payout_id']);
            $table->dropColumn('organization_payout_id');
        });

        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn([
                'organization_id',
                'organization_commission_rate',
                'organization_referred_at',
            ]);
        });
    }
};
