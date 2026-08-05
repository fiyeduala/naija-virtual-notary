<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Streams the sealed PDF inline so an admin can read it in the browser without
 * downloading. Works for every completed request — the ones a partner notary
 * sealed as well as the ones the admin desk handled.
 *
 * Downloads go through Client\DocumentDownloadController, which already allows
 * admins; this route only adds in-browser viewing.
 */
class NotarizedDocumentController extends Controller
{
    public function view(NotarizationRequest $request)
    {
        $final = $request->finalDocument()->first();
        abort_unless($final, 404, 'This request has no notarized document yet.');
        abort_unless(
            Storage::disk('private')->exists($final->file_url),
            404,
            'The sealed file is recorded but missing from storage.'
        );

        AuditLogger::record('document.viewed', 'notarization_request', $request->id, [
            'document_id' => $final->id,
        ], Auth::id());

        return Storage::disk('private')->response(
            $final->file_url,
            $request->reference . '-notarized.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
