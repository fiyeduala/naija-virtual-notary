<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This is not the category you booked."
 *
 * A client picks a service off a notary's price list before anyone has read
 * their document, so they pick by guess: an affidavit priced as a travel
 * consent letter, a deed booked as an attestation. Until now the only answers
 * were to notarize the wrong thing, or to cancel and refund — and a refund
 * means the client starts over, pays the gateway fee twice, and usually never
 * comes back.
 *
 * So the money stays where it is. The desk raises a query naming what the
 * document actually is, the client re-picks, and the difference (if the right
 * category costs more) becomes an ordinary outstanding balance on the same
 * request — which this codebase already knows how to collect, because part
 * payment has always been supported.
 *
 * Deliberately columns rather than a new RequestStatus case: a query can be
 * raised against a request in any live state, and a new case would force every
 * match() over the enum in the app to grow a branch that mostly means "carry
 * on as before".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            // Open when set and category_query_resolved_at is null.
            $table->timestamp('category_query_at')->nullable()->index();
            $table->foreignId('category_query_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('category_query_reason')->nullable();

            // A recommendation, not a decision. The client still chooses, so
            // that the price they end up paying is one they agreed to.
            $table->foreignId('category_suggested_service_id')->nullable()
                ->constrained('notary_services')->nullOnDelete();

            $table->timestamp('category_query_resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_query_by');
            $table->dropConstrainedForeignId('category_suggested_service_id');
            $table->dropColumn([
                'category_query_at',
                'category_query_reason',
                'category_query_resolved_at',
            ]);
        });
    }
};
