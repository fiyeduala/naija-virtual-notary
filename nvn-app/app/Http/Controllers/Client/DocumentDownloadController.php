<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DocumentDownloadController extends Controller
{
    /** Download the final notarized document (client or the handling notary/admin). */
    public function download(NotarizationRequest $request)
    {
        $user = Auth::user();
        $allowed = $user->id === $request->client_id
            || $user->id === $request->notary?->user_id
            || $user->isAdmin()
            || $user->id === $request->handled_by;

        abort_unless($allowed, 403);

        $final = $request->finalDocument()->first();
        abort_unless($final, 404, 'No notarized document available yet.');

        AuditLogger::record('document.downloaded', 'notarization_request', $request->id, [
            'document_id' => $final->id,
        ], $user->id);

        return Storage::disk('private')->download(
            $final->file_url,
            $request->reference . '-notarized.pdf'
        );
    }
}
