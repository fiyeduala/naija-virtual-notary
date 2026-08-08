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

        $final = $this->chosen($request);
        abort_unless($final, 404, 'No notarized document available yet.');

        AuditLogger::record('document.downloaded', 'notarization_request', $request->id, [
            'document_id' => $final->id,
        ], $user->id);

        return Storage::disk('private')->download(
            $final->file_url,
            $final->original_filename ?: $request->reference . '-notarized.pdf'
        );
    }

    /**
     * Which sealed document was asked for.
     *
     * A request has one sealed PDF per document notarized, so the link carries
     * ?document=<id>. Omitting it downloads the first, which is what every link
     * written before additional documents were sealed does — those still work
     * and still mean "the notarized document" on a single-document request.
     *
     * The id is matched inside this request's own finals rather than looked up
     * directly, so it cannot be turned into a way to read another client's file.
     */
    private function chosen(NotarizationRequest $request): ?\App\Models\RequestDocument
    {
        $finals = $request->finalDocuments;

        return request('document')
            ? $finals->firstWhere('id', (int) request('document'))
            : $finals->first();
    }
}
