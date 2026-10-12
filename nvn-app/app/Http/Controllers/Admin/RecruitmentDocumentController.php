<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationDocument;
use App\Services\Recruitment\RecruitmentDocumentService;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A member of staff reading a candidate's file.
 *
 * The route is `auth` + `verified.otp` and nothing more, deliberately: a
 * recruitment reviewer is not necessarily an administrator, so `role:admin`
 * would lock out the people whose job this is. The real decision is
 * ApplicationDocument::readableBy(), reached through the service, and it asks
 * two separate questions — is this application yours to review, and, for an
 * identity or guarantor file, do you hold sensitive.view.
 *
 * So the id in the URL is not the authority. Typing somebody else's document
 * id produces a 403, and a reviewer who can see the application still gets a
 * 403 on the identity paperwork attached to it.
 *
 * Nothing here is ever emailed. The link only works inside a live staff
 * session, which is the whole reason the files are on the private disk.
 */
class RecruitmentDocumentController extends Controller
{
    public function __construct(private RecruitmentDocumentService $documents) {}

    public function download(ApplicationDocument $document): StreamedResponse
    {
        try {
            return $this->documents->downloadForStaff($document, auth()->user());
        } catch (RuntimeException $exception) {
            // The message names the file and the rule; the candidate's
            // paperwork does not need to be described to somebody who was
            // just refused it.
            abort(403, 'You are not authorized to read this file.');
        }
    }
}
