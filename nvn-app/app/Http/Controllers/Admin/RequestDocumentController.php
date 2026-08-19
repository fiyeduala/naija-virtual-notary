<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\RequestDocument;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Streams a client's *uploaded* document inline, for an admin.
 *
 * NotarizedDocumentController serves the sealed output, which only exists once
 * the work is done. There was no way to look at what a client actually sent
 * before that — and the requests most worth opening are the ones with nothing
 * sealed at all: a draft that stalled before a notary was chosen, where the
 * only way to judge whether it is worth chasing is to read the document.
 *
 * Notaries have had this via Notary\NotaryRequestController::document() all
 * along; this is the same thing without the assignment check, because an admin
 * can act on any request and an unassigned draft has no notary to scope to.
 */
class RequestDocumentController extends Controller
{
    public function view(NotarizationRequest $request, RequestDocument $document)
    {
        // The id in the URL is always matched inside this request's own set,
        // so /admin/requests/5/documents/99 cannot reach another client's file.
        abort_unless($document->request_id === $request->id, 404);

        abort_unless(
            Storage::disk('private')->exists($document->file_url),
            404,
            'The file is recorded against this request but missing from storage.'
        );

        $ext = strtolower(pathinfo(
            $document->original_filename ?: $document->file_url,
            PATHINFO_EXTENSION,
        ));

        // Only the types a browser can actually render are served inline. A
        // .docx sent with Content-Disposition: inline just triggers a download
        // with a worse filename, so it is offered as an attachment instead.
        $inlineTypes = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
        ];

        $mime     = $inlineTypes[$ext] ?? 'application/octet-stream';
        $filename = $document->original_filename ?: ($request->reference . '.' . $ext);

        AuditLogger::record('document.viewed_by_admin', 'notarization_request', $request->id, [
            'document_id' => $document->id,
            'file_type'   => $document->file_type,
        ], Auth::id());

        return Storage::disk('private')->response($document->file_url, $filename, [
            'Content-Type'        => $mime,
            'Content-Disposition' => (isset($inlineTypes[$ext]) ? 'inline' : 'attachment')
                . '; filename="' . addslashes($filename) . '"',
        ]);
    }
}
