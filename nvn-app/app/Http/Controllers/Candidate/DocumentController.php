<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\OwnedByCandidate;
use App\Http\Controllers\Controller;
use App\Models\ApplicationDocument;
use App\Services\Recruitment\RecruitmentDocumentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A candidate downloading one of their own files.
 *
 * Authorized twice over on every single request — the trait checks the
 * application is theirs, and the service checks the document is theirs and of a
 * kind they may read. There is no signed URL here and no permanent link: an
 * email says "sign in to see this", because a document URL in an inbox outlives
 * the inbox and gets forwarded.
 */
class DocumentController extends Controller
{
    use OwnedByCandidate;

    public function __construct(private RecruitmentDocumentService $documents) {}

    public function show(ApplicationDocument $document): StreamedResponse
    {
        $document->loadMissing('application');

        abort_unless(
            $document->application
                && $document->application->candidate_id === $this->candidate()->id,
            404,
        );

        return $this->documents->downloadForCandidate($document, $this->candidate());
    }
}
