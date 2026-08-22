<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offsite notarization: a job the notary sold themselves.
 *
 * A notary public meets a client away from the platform — in their office, at a
 * bank, at a client's home — and still wants the notarization done digitally,
 * with their own e-signature, stamp and seal on a proper sealed PDF. Until now
 * the only way in was for the client to book through the marketplace, which is
 * the wrong shape entirely: there is no notary to choose, no price to agree, no
 * appointment to keep, and no client account.
 *
 * So the platform sells the only part it is actually providing here — the
 * sealing — at a flat fee per document, set by the admin. It takes the payment,
 * hands back the sealed file, and does nothing else. No commission split, no
 * payout, no fallback clock, no session, no client-facing anything.
 *
 * Reusing notarization_requests rather than a new table is deliberate: the
 * editor, the placement engine, the PDF sealer, the audit trail and the
 * document store all key off a request, and a parallel table would mean a
 * second copy of every one of them. Two columns is the whole cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            // Indexed because almost every existing query now has to say "and
            // not one of these" — the desk queues, the overdue sweep, the
            // unpaid-client follow-up. See NotarizationRequest::scopeOnDeskOf().
            $table->boolean('is_offsite')->default(false)->index();

            // The price of ONE document, in minor units, frozen at the moment
            // the job was created. The fee lives in platform settings and an
            // admin may change it tomorrow; a notary who is halfway through
            // paying must not find the total has moved under them.
            //
            // Nullable, and null is the ordinary case: a marketplace request
            // takes its price from the service the client chose. This column is
            // what stands in when there is no service to ask.
            $table->integer('unit_fee_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->dropColumn(['is_offsite', 'unit_fee_minor']);
        });
    }
};
