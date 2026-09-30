<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's own signature, placed by the notary.
 *
 * Clients often send their signature as an image — drawn on the intake canvas,
 * or photographed and uploaded — meaning "sign it here for me". Until now the
 * editor could only place marks belonging to a notary (document_placements.
 * asset_id points at notary_assets), so that signature had to be pasted in by
 * hand outside the platform or the job sat waiting for a call that was never
 * needed.
 *
 * A placement now names either a notary asset or one of the request's own
 * uploads, never both. Keeping the client's signature in its own column rather
 * than widening asset_id is what keeps the two apart everywhere it matters:
 * PdfNotarizationService::sealAuthor() reads asset_id to decide whose seal is
 * on the document, so a client signature — asset_id null — can never be
 * mistaken for a notarial act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_placements', function (Blueprint $table) {
            $table->foreignId('signature_document_id')
                ->nullable()
                ->after('asset_id')
                ->constrained('request_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_placements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signature_document_id');
        });
    }
};
