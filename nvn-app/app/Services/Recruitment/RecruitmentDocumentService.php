<?php

namespace App\Services\Recruitment;

use App\Models\ApplicationDocument;
use App\Models\Candidate;
use App\Models\JobApplication;
use App\Models\OnboardingItem;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\RecruitmentPermission;
use App\Support\UploadGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every applicant file goes in and comes out through here.
 *
 * The rules, in the order they matter:
 *
 *   - Nothing applicant-uploaded touches the public disk. Everything is on
 *     `private`, which lives in storage/app/private and is not reachable from
 *     the web root by any URL, with or without a symlink.
 *   - A download is authorized per request, against the signed-in actor and
 *     this exact file. There is no signed URL, no uuid-as-password and no
 *     "if you know the id you may have it" path anywhere in this module.
 *   - A sensitive file — identity paperwork, anything a guarantor sent —
 *     additionally needs sensitive.view. The reviewer marking a writing
 *     exercise cannot open it, and neither can a coordinator.
 *   - Files are streamed as attachments with no-store and nosniff, never
 *     rendered inline. A PDF rendered inline runs in the viewer's origin, and
 *     this platform notarizes documents for a living; it does not need to find
 *     out the hard way what an active-content PDF does in an admin's browser.
 *   - Uploading is not verifying. `verification_status` is set by a person who
 *     looked at the document, with their name and the date against it.
 */
class RecruitmentDocumentService
{
    /** A staff download. Audited, because somebody opened somebody's file. */
    public function downloadForStaff(ApplicationDocument $document, ?User $user): StreamedResponse
    {
        if (! $document->readableBy($user)) {
            throw new RuntimeException('Not authorized to read this document.');
        }

        AuditLogger::record('recruitment_document.read', 'application_document', $document->id, [
            'kind'      => $document->kind,
            'sensitive' => (bool) $document->is_sensitive,
        ], $user?->id);

        return $this->stream($document);
    }

    /** A candidate downloading their own file. */
    public function downloadForCandidate(
        ApplicationDocument $document,
        ?Candidate $candidate,
    ): StreamedResponse {
        if (! $document->readableByCandidate($candidate)) {
            throw new RuntimeException('Not authorized to read this document.');
        }

        return $this->stream($document);
    }

    /**
     * The bytes, as an attachment.
     *
     * Content-Disposition carries a sanitised filename: the stored name came
     * from a candidate's computer and a quote or a newline in it would let
     * somebody write their own headers.
     */
    private function stream(ApplicationDocument $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk ?: 'private');

        if (! $document->path || ! $disk->exists($document->path)) {
            throw new RuntimeException('That file is no longer stored.');
        }

        $name = UploadGuard::safeOriginalName($document->original_name ?: 'document');

        return $disk->download($document->path, $name, [
            'Content-Type'            => 'application/octet-stream',
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * A candidate submitting a checklist item.
     *
     * The item decides whether what arrives is sensitive, so an identity
     * document is sensitive from the moment it lands rather than from whenever
     * somebody remembers to tick a box. `submitted` is as far as this goes:
     * a member of staff moves it to verified or not verified, after looking.
     */
    public function uploadOnboarding(
        OnboardingItem $item,
        Candidate $candidate,
        UploadedFile $file,
        ?string $note = null,
    ): ApplicationDocument {
        $application = $item->application;

        if (! $application || $application->candidate_id !== $candidate->id) {
            throw new RuntimeException('Not authorized to submit against this item.');
        }

        if ($item->kind !== OnboardingItem::KIND_DOCUMENT) {
            throw new RuntimeException('This item does not take a file.');
        }

        if (in_array($item->status, [OnboardingItem::VERIFIED, OnboardingItem::WAIVED], true)) {
            throw new RuntimeException('This item has already been settled.');
        }

        $stored = UploadGuard::store($file, 'onboarding', 'recruitment/onboarding');

        try {
            return DB::transaction(function () use ($item, $application, $candidate, $stored, $note) {
                $kind = $item->item_key === 'guarantor'
                    ? ApplicationDocument::KIND_GUARANTOR
                    : ApplicationDocument::KIND_IDENTITY;

                $version = (int) $application->documents()
                    ->where('kind', $kind)
                    ->max('version');

                $document = $application->documents()->create([
                    'candidate_id'          => $candidate->id,
                    'kind'                  => $kind,
                    'disk'                  => 'private',
                    'path'                  => $stored['path'],
                    'original_name'         => $stored['original_name'],
                    'mime'                  => $stored['mime'],
                    'size'                  => $stored['size'],
                    'scan_status'           => $stored['scan_status'],
                    'scanned_at'            => $stored['scanned_at'],
                    'is_sensitive'          => true,
                    'uploaded_by_candidate' => true,
                    'version'               => $version + 1,
                    'verification_status'   => ApplicationDocument::VERIFICATION_PENDING,
                ]);

                $item->forceFill([
                    'status'      => OnboardingItem::SUBMITTED,
                    'document_id' => $document->id,
                    'note'        => $note ? Str::limit(trim($note), 1000, '') : $item->note,
                ])->save();

                // The kind and the size, never the name and never the bytes.
                AuditLogger::record('recruitment_document.submitted', 'application_document', $document->id, [
                    'kind' => $kind,
                    'size' => $stored['size'],
                    'scan' => $stored['scan_status'],
                ]);

                return $document;
            });
        } catch (\Throwable $e) {
            // Storage is not transactional, so a rolled-back write must take
            // its file with it rather than leave an orphan on disk.
            Storage::disk('private')->delete($stored['path']);

            throw $e;
        }
    }

    /**
     * A non-document checklist item the candidate has answered in writing.
     *
     * Confirmations and referee details come through here. Same rule as a
     * file: it becomes `submitted`, and a person decides the rest.
     */
    public function submitOnboardingAnswer(
        OnboardingItem $item,
        Candidate $candidate,
        string $note,
    ): OnboardingItem {
        $application = $item->application;

        if (! $application || $application->candidate_id !== $candidate->id) {
            throw new RuntimeException('Not authorized to submit against this item.');
        }

        if ($item->kind === OnboardingItem::KIND_DOCUMENT) {
            throw new RuntimeException('This item needs a file.');
        }

        if (in_array($item->status, [OnboardingItem::VERIFIED, OnboardingItem::WAIVED], true)) {
            throw new RuntimeException('This item has already been settled.');
        }

        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('Please write an answer before submitting.');
        }

        $item->forceFill([
            'status' => OnboardingItem::SUBMITTED,
            'note'   => Str::limit($note, 2000, ''),
        ])->save();

        return $item->fresh();
    }

    /**
     * A member of staff recording what they found.
     *
     * The distinction this method exists to keep: a file arriving is not a
     * check passing. Until somebody sets this, the status says pending, and
     * the admin screen says pending rather than showing a tick next to an
     * unexamined document.
     */
    public function recordVerification(
        OnboardingItem $item,
        ?User $actor,
        string $status,
        ?string $note = null,
    ): OnboardingItem {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::VIEW_SENSITIVE)) {
            throw new RuntimeException('Not authorized to verify onboarding items.');
        }

        if (! RecruitmentPermission::canReview($actor, $item->application)) {
            throw new RuntimeException('Not authorized to verify onboarding items.');
        }

        $allowed = [
            OnboardingItem::PENDING,
            OnboardingItem::SUBMITTED,
            OnboardingItem::VERIFIED,
            OnboardingItem::NOT_VERIFIED,
            OnboardingItem::WAIVED,
        ];

        if (! in_array($status, $allowed, true)) {
            throw new RuntimeException('Unknown status.');
        }

        DB::transaction(function () use ($item, $actor, $status, $note) {
            $item->forceFill([
                'status'      => $status,
                'note'        => $note ? Str::limit(trim($note), 2000, '') : $item->note,
                'verified_by' => $actor?->id,
                'verified_at' => now(),
            ])->save();

            if ($item->document) {
                $item->document->forceFill([
                    'verification_status' => match ($status) {
                        OnboardingItem::VERIFIED     => ApplicationDocument::VERIFICATION_OK,
                        OnboardingItem::NOT_VERIFIED => ApplicationDocument::VERIFICATION_FAILED,
                        default                      => ApplicationDocument::VERIFICATION_PENDING,
                    },
                    'verified_by'       => $actor?->id,
                    'verified_at'       => now(),
                    'verification_note' => $note ? Str::limit(trim($note), 1000, '') : null,
                ])->save();
            }

            AuditLogger::record('recruitment_checks.verified', 'onboarding_item', $item->id, [
                'item'   => $item->item_key,
                'status' => $status,
            ], $actor?->id);
        });

        return $item->fresh();
    }

    /**
     * A member of staff uploading the offer document itself.
     *
     * This is the path §8 means by "an uploaded document": somebody attaches
     * the letter they wrote, and the offer points at it. It is not sensitive
     * in the identity-paperwork sense — the candidate is meant to read it —
     * but it is still private storage and still an authorized download.
     */
    public function uploadOfferDocument(
        JobApplication $application,
        ?User $actor,
        UploadedFile $file,
    ): ApplicationDocument {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::APPROVE_OFFER)) {
            throw new RuntimeException('Not authorized to upload offer documents.');
        }

        if (! RecruitmentPermission::canReview($actor, $application)) {
            throw new RuntimeException('Not authorized to upload offer documents.');
        }

        $stored = UploadGuard::store($file, 'onboarding', 'recruitment/offers');

        try {
            $version = (int) $application->documents()
                ->where('kind', ApplicationDocument::KIND_OFFER)
                ->max('version');

            $document = $application->documents()->create([
                'candidate_id'          => $application->candidate_id,
                'kind'                  => ApplicationDocument::KIND_OFFER,
                'disk'                  => 'private',
                'path'                  => $stored['path'],
                'original_name'         => $stored['original_name'],
                'mime'                  => $stored['mime'],
                'size'                  => $stored['size'],
                'scan_status'           => $stored['scan_status'],
                'scanned_at'            => $stored['scanned_at'],
                'is_sensitive'          => false,
                'uploaded_by_candidate' => false,
                'uploaded_by_user_id'   => $actor?->id,
                'version'               => $version + 1,
            ]);

            AuditLogger::record('recruitment_document.uploaded', 'application_document', $document->id, [
                'kind' => ApplicationDocument::KIND_OFFER,
                'size' => $stored['size'],
            ], $actor?->id);

            return $document;
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($stored['path']);

            throw $e;
        }
    }
}
