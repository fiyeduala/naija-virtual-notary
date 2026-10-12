<?php

namespace App\Services\Recruitment;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Models\JobApplication;
use App\Models\Offer;
use App\Models\OnboardingItem;
use App\Models\ReferenceContact;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Offers, and the checklist that follows one.
 *
 * Three things this class refuses to do, each because the alternative would be
 * a lie on a document somebody relies on:
 *
 *   - It will not invent terms. The body of an offer is either text an
 *     administrator wrote and approved, or a document they uploaded. There is
 *     no generated salary, no generated start date, no generated notice period.
 *   - It will not call a candidate's click a signature. Accepting here records
 *     that they said yes, with a timestamp, and the wording says exactly that.
 *     It is not an electronic signature and this platform does not claim it is
 *     — which is a careful distinction for a notarization business to get right.
 *   - It will not create an account or grant a privilege. Accepting an offer
 *     and being marked hired changes recruitment records and nothing else.
 *
 * The checklist is built from the vacancy's own settings. Identity documents
 * and guarantors are off unless somebody turned them on for that role and said
 * why, and both land as sensitive rows that an ordinary reviewer cannot open.
 */
class OfferService
{
    public function __construct(
        private RecruitmentOutbox $outbox,
        private ApplicationStageService $stages,
    ) {}

    /**
     * @param  array{body?: ?string, template_key?: ?string, salary_minor?: ?int, currency?: ?string, pay_period?: ?string, compensation_type?: ?string, compensation_note?: ?string, start_date?: mixed, document_id?: ?int}  $data
     *
     * @throws StageTransitionException
     */
    public function draft(JobApplication $application, ?User $actor, array $data): Offer
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::APPROVE_OFFER)) {
            throw new StageTransitionException('You do not have permission to prepare offers.');
        }

        if (! $application->isActive()) {
            throw new StageTransitionException('This application is closed.');
        }

        $body     = trim((string) ($data['body'] ?? ''));
        $document = $data['document_id'] ?? null;

        if ($body === '' && ! $document) {
            throw new StageTransitionException(
                'An offer needs either approved wording or an uploaded document. '
                . 'Nothing here will write employment terms for you.',
            );
        }

        return DB::transaction(function () use ($application, $actor, $data, $body, $document) {
            $offer = $application->offers()->create([
                'status'            => Offer::DRAFT,
                'template_key'      => $data['template_key'] ?? null,
                'body'              => $body ?: null,
                'document_id'       => $document,
                'salary_minor'      => $data['salary_minor'] ?? null,
                'currency'          => $data['currency'] ?? null,
                'pay_period'        => $data['pay_period'] ?? null,
                'compensation_type' => $data['compensation_type'] ?? null,
                'compensation_note' => isset($data['compensation_note'])
                    ? Str::limit((string) $data['compensation_note'], 2000, '')
                    : null,
                'start_date'        => $data['start_date'] ?? null,
                'created_by'        => $actor?->id,
            ]);

            AuditLogger::record('recruitment_offer.drafted', 'offer', $offer->id, [
                'application' => $application->reference,
            ], $actor?->id);

            return $offer;
        });
    }

    /** A second, deliberate step: somebody signs the offer off before it goes. */
    public function approve(Offer $offer, ?User $actor): Offer
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::APPROVE_OFFER)) {
            throw new StageTransitionException('You do not have permission to approve offers.');
        }

        if ($offer->status !== Offer::DRAFT) {
            throw new StageTransitionException('Only a draft offer can be approved.');
        }

        $offer->forceFill([
            'status'      => Offer::APPROVED,
            'approved_by' => $actor?->id,
            'approved_at' => now(),
        ])->save();

        AuditLogger::record('recruitment_offer.approved', 'offer', $offer->id, [
            'application' => $offer->application->reference,
        ], $actor?->id);

        return $offer->fresh();
    }

    /**
     * Send an approved offer and move the application into offer and checks.
     *
     * The email carries no terms and no attachment. It says an offer is ready
     * and links to the candidate's own page, which checks who is asking before
     * it shows anything.
     */
    public function send(Offer $offer, ?User $actor): Offer
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::APPROVE_OFFER)) {
            throw new StageTransitionException('You do not have permission to send offers.');
        }

        if (! $offer->isSendable()) {
            throw new StageTransitionException(
                'This offer has not been approved, or it has already gone out.',
            );
        }

        $application = $offer->application;

        if (! $application->isActive()) {
            throw new StageTransitionException('This application is closed.');
        }

        return DB::transaction(function () use ($offer, $application, $actor) {
            $offer->forceFill([
                'status'  => Offer::SENT,
                'sent_at' => now(),
            ])->save();

            if ($application->stage !== ApplicationStage::OfferChecks) {
                $this->stages->move($application, ApplicationStage::OfferChecks, $actor, [
                    'skip_permission' => true,
                    'reason'          => 'Offer sent',
                ]);
            }

            $this->outbox->queue(RecruitmentTemplates::OFFER_SENT, $application, [
                'event_key'       => $this->outbox->eventKey(
                    RecruitmentTemplates::OFFER_SENT,
                    $application,
                    'o' . $offer->id,
                ),
                'data'            => $this->outbox->baseData($application),
                'action_url'      => route('candidate.login'),
                'guard'           => ['offer' => $offer->id],
            ]);

            AuditLogger::record('recruitment_offer.sent', 'offer', $offer->id, [
                'application' => $application->reference,
            ], $actor?->id);

            return $offer->fresh();
        });
    }

    /**
     * The candidate's answer.
     *
     * Deliberate wording throughout: this records that somebody said yes or no
     * on a given date. It is not an electronic signature, it is not notarized,
     * and the candidate's own screen says so before they press the button.
     * Accepting also does NOT mark anybody hired — that stays a manual
     * decision once the checklist is resolved.
     */
    public function recordResponse(Offer $offer, bool $accepted, ?string $note = null): Offer
    {
        if (! $offer->awaitingResponse()) {
            throw new StageTransitionException('This offer is not waiting for an answer.');
        }

        $note = $note ? Str::limit(trim($note), 1000, '') : null;

        return DB::transaction(function () use ($offer, $accepted, $note) {
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Offer::SENT) {
                throw new StageTransitionException('This offer has already been answered.');
            }

            $locked->forceFill([
                'status'         => $accepted ? Offer::ACCEPTED : Offer::DECLINED,
                'responded_at'   => now(),
                'response_note'  => $accepted ? $note : null,
                'decline_reason' => $accepted ? null : $note,
            ])->save();

            AuditLogger::record(
                $accepted ? 'recruitment_offer.accepted' : 'recruitment_offer.declined',
                'offer',
                $locked->id,
                ['application' => $locked->application->reference],
            );

            if ($accepted) {
                $this->buildChecklist($locked->application, null);
            }

            return $locked->fresh();
        });
    }

    /** Pulled before it was answered, or after a decline. */
    public function withdrawOffer(Offer $offer, ?User $actor, string $reason): Offer
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::APPROVE_OFFER)) {
            throw new StageTransitionException('You do not have permission to withdraw offers.');
        }

        if (in_array($offer->status, [Offer::ACCEPTED, Offer::WITHDRAWN], true)) {
            throw new StageTransitionException(
                'An accepted offer cannot be withdrawn here. Reject the application instead, '
                . 'with a reason, so the record says what actually happened.',
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new StageTransitionException('Withdrawing an offer needs a reason.');
        }

        DB::transaction(function () use ($offer, $actor, $reason) {
            $offer->forceFill([
                'status'         => Offer::WITHDRAWN,
                'decline_reason' => Str::limit($reason, 1000, ''),
                'responded_at'   => now(),
            ])->save();

            // A pending "your offer is ready" email must not arrive after the
            // offer has been pulled.
            $this->outbox->cancelPending(
                $offer->application,
                [RecruitmentTemplates::OFFER_SENT],
                'Offer withdrawn',
            );

            AuditLogger::record('recruitment_offer.withdrawn', 'offer', $offer->id, [
                'application' => $offer->application->reference,
            ], $actor?->id);
        });

        return $offer->fresh();
    }

    /**
     * Build the checks checklist for an accepted offer.
     *
     * Three rules are enforced here rather than left to whoever fills the
     * form in:
     *
     *   - Identity documents are requested only when the vacancy says so.
     *     Nothing is asked for at application time, and nothing is asked for
     *     at all unless somebody switched it on for that role.
     *   - A guarantor is requested only when the vacancy says so AND a reason
     *     has been written down. A role that turned guarantors on without
     *     explaining why gets no guarantor item, because "we always do" is
     *     not a purpose that can be shown to the person being asked.
     *   - Every identity and guarantor row is marked sensitive, which is what
     *     keeps it out of an ordinary reviewer's view and out of the ordinary
     *     export.
     *
     * Idempotent: the item key is the identity of the row, so running this
     * twice adds nothing and overwrites nothing a candidate has submitted.
     */
    public function buildChecklist(JobApplication $application, ?User $actor, bool $notify = true): int
    {
        $vacancy = $application->vacancy;
        $created = 0;
        $items   = [];

        if (config('recruitment.checks.confirm_terms', true)) {
            $items[] = [
                'item_key'     => 'confirm_terms',
                'label'        => 'Confirm you accept the terms as issued',
                'kind'         => OnboardingItem::KIND_CONFIRMATION,
                'is_sensitive' => false,
                'is_required'  => true,
                'instructions' => 'Reply on this page to record that you accept the offer as '
                    . 'issued. This is a record of your agreement and is not an electronic '
                    . 'signature. Anything that needs signing will be sent to you separately.',
            ];
        }

        $referees = max(0, (int) config('recruitment.checks.professional_references', 0));

        for ($i = 1; $i <= $referees; $i++) {
            $items[] = [
                'item_key'     => 'reference_' . $i,
                'label'        => 'Professional referee ' . $i,
                'kind'         => OnboardingItem::KIND_REFERENCE,
                'is_sensitive' => false,
                'is_required'  => true,
                'instructions' => 'Somebody who has worked with you and has agreed to be '
                    . 'contacted about your work. We will not contact them until you have '
                    . 'confirmed they agreed.',
            ];
        }

        if ($vacancy?->identity_documents_required) {
            $items[] = [
                'item_key'     => 'identity_document',
                'label'        => 'Proof of identity',
                'kind'         => OnboardingItem::KIND_DOCUMENT,
                'is_sensitive' => true,
                'is_required'  => true,
                'instructions' => 'A government-issued identity document. It is stored privately, '
                    . 'is visible only to the staff who handle onboarding, and is never sent by '
                    . 'email.',
            ];
        }

        if ($vacancy?->guarantors_required && trim((string) $vacancy->guarantor_reason) !== '') {
            $items[] = [
                'item_key'     => 'guarantor',
                'label'        => 'Guarantor',
                'kind'         => OnboardingItem::KIND_REFERENCE,
                'is_sensitive' => true,
                'is_required'  => true,
                'instructions' => 'Why this role asks for a guarantor: '
                    . trim((string) $vacancy->guarantor_reason)
                    . ' A guarantor is not the same as a referee, and the referees you give '
                    . 'above are not used for this.',
            ];
        }

        DB::transaction(function () use ($application, $items, &$created) {
            foreach ($items as $item) {
                $exists = $application->onboardingItems()
                    ->where('item_key', $item['item_key'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                $application->onboardingItems()->create($item + [
                    'status' => OnboardingItem::PENDING,
                ]);

                $created++;
            }
        });

        if ($created === 0) {
            return 0;
        }

        AuditLogger::record('recruitment_checks.requested', 'application', $application->id, [
            'items' => $created,
        ], $actor?->id);

        if ($notify) {
            $this->outbox->queue(RecruitmentTemplates::CHECKS_SENT, $application, [
                'event_key'  => $this->outbox->eventKey(
                    RecruitmentTemplates::CHECKS_SENT,
                    $application,
                    'n' . $application->onboardingItems()->count(),
                ),
                'data'       => $this->outbox->baseData($application),
                'action_url' => route('candidate.login'),
            ]);
        }

        return $created;
    }

    /**
     * Record a referee or guarantor the candidate has given us.
     *
     * `consent_confirmed` is the candidate stating that this person has agreed
     * to be contacted. Without it the row exists and nobody may contact it:
     * ReferenceContact::readyToContact() is false, and contacting is in any
     * case a separate action a member of staff takes by hand. Nothing in this
     * module emails a third party on its own.
     */
    public function recordReferee(
        JobApplication $application,
        string $type,
        array $data,
        bool $consentConfirmed,
    ): ReferenceContact {
        if (! in_array($type, [ReferenceContact::REFERENCE, ReferenceContact::GUARANTOR], true)) {
            throw new StageTransitionException('Unknown contact type.');
        }

        $purpose = $type === ReferenceContact::GUARANTOR
            ? trim((string) $application->vacancy?->guarantor_reason)
            : 'To ask about your work with them, if we reach that stage.';

        return $application->referenceContacts()->create([
            'contact_type'         => $type,
            'name'                 => $this->short($data['name'] ?? '', 160),
            'relationship'         => $this->short($data['relationship'] ?? '', 120) ?: null,
            'organisation'         => $this->short($data['organisation'] ?? '', 160) ?: null,
            'email'                => $this->short($data['email'] ?? '', 190) ?: null,
            'phone'                => $this->short($data['phone'] ?? '', 40) ?: null,
            'purpose_shown'        => $purpose ?: null,
            'consent_confirmed'    => $consentConfirmed,
            'consent_confirmed_at' => $consentConfirmed ? now() : null,
        ]);
    }

    /**
     * Mark that a member of staff contacted a referee, after the fact.
     *
     * Separate and manual on purpose: a third party is never contacted
     * automatically, so this records a phone call somebody made rather than
     * making one.
     */
    public function recordRefereeContact(
        ReferenceContact $contact,
        ?User $actor,
        string $outcome,
        ?string $note = null,
    ): ReferenceContact {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::APPROVE_OFFER)) {
            throw new StageTransitionException('You do not have permission to record this.');
        }

        if (! $contact->readyToContact()) {
            throw new StageTransitionException(
                'This person has not been confirmed as having agreed to be contacted.',
            );
        }

        if (! array_key_exists($outcome, ReferenceContact::OUTCOMES)) {
            throw new StageTransitionException('Unknown outcome.');
        }

        $contact->forceFill([
            'outcome'      => $outcome,
            'note'         => $note ? $this->short($note, 2000) : $contact->note,
            'contacted_at' => now(),
            'contacted_by' => $actor?->id,
        ])->save();

        AuditLogger::record('recruitment_reference.contacted', 'reference_contact', $contact->id, [
            'type'    => $contact->contact_type,
            'outcome' => $outcome,
        ], $actor?->id);

        return $contact->fresh();
    }

    /** Trim and cap a free-text field. */
    private function short(mixed $value, int $length): string
    {
        return Str::limit(trim((string) $value), $length, '');
    }
}
