<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The partner fee becomes a yearly membership.
 *
 * onboarding_fee_paid_at answers "did they ever pay?", which was the whole
 * question while the fee was once and for all. It cannot answer "are they paid
 * up now", so a partner who joined two years ago and a partner who joined last
 * week looked identical to every listing and booking check on the platform.
 *
 * membership_expires_at is that second question. It is extended by a year each
 * time a partner fee clears, whether on the checkout page or recorded by an
 * admin from a bank transfer.
 *
 * BACKFILL: every existing partner keeps their listing. Working the expiry out
 * from the date they paid would retire most of them the moment this runs —
 * including the notaries migrated from the old WordPress site, who never agreed
 * to a renewal date and have not been told one exists. They are given a year
 * from their payment date OR ninety days from today, whichever is later, so the
 * platform has a season to tell them before anything lapses.
 */
return new class extends Migration
{
    /** Days of runway the existing partners get, at minimum. */
    private const GRACE_DAYS = 90;

    public function up(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            $table->timestamp('membership_expires_at')
                ->nullable()
                ->after('onboarding_fee_paid_at');

            // Set when a lapse notice was last sent, so the reminder command can
            // run every day without emailing the same partner every day.
            $table->timestamp('membership_reminded_at')
                ->nullable()
                ->after('membership_expires_at');
        });

        $floor = now()->addDays(self::GRACE_DAYS);

        DB::table('notary_profiles')
            ->whereNotNull('onboarding_fee_paid_at')
            ->orderBy('id')
            ->select('id', 'onboarding_fee_paid_at')
            ->chunkById(200, function ($profiles) use ($floor) {
                foreach ($profiles as $profile) {
                    $anniversary = \Illuminate\Support\Carbon::parse($profile->onboarding_fee_paid_at)->addYear();

                    DB::table('notary_profiles')
                        ->where('id', $profile->id)
                        ->update([
                            'membership_expires_at' => $anniversary->greaterThan($floor)
                                ? $anniversary
                                : $floor,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            $table->dropColumn(['membership_expires_at', 'membership_reminded_at']);
        });
    }
};
