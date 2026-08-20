<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listing becomes something granted, not something taken.
 *
 * Until now an approved notary who had uploaded three files and a bank account
 * could put themselves in the marketplace. Every check that ran was a check on
 * whether the files *existed*; none of them — none of them could — ask whether
 * the images on those files were the right images. One notary went live with
 * the wrong stamp and seal, a client booked him, and the job could not be
 * finished by anybody.
 *
 * So `public_listing_enabled` now moves only when an admin moves it, and these
 * columns carry the request that asks them to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            // When the notary said they were ready. Set on request, cleared on
            // decision, so "requested and not yet listed" is the whole queue.
            $table->timestamp('listing_requested_at')->nullable()->index();

            // When an admin actually put them in the marketplace. Kept apart
            // from public_listing_enabled because that boolean also goes false
            // again on an unlist, and then tells you nothing about whether the
            // marks were ever looked at.
            $table->timestamp('listed_at')->nullable();

            // Why a listing was declined, in the notary's own email. Separate
            // from review_notes, which belongs to the application decision and
            // would otherwise be overwritten by a much later, smaller refusal.
            $table->text('listing_review_notes')->nullable();
        });

        // Everyone already in the marketplace stays in it. This change is about
        // who gets in from today; retro-applying it would empty the marketplace
        // and strand partners who have paid for a year of being findable.
        DB::table('notary_profiles')
            ->where('public_listing_enabled', true)
            ->update([
                'listed_at'            => DB::raw('COALESCE(approved_at, created_at)'),
                'listing_requested_at' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            $table->dropColumn(['listing_requested_at', 'listed_at', 'listing_review_notes']);
        });
    }
};
