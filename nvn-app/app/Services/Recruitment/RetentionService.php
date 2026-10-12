<?php

namespace App\Services\Recruitment;

use App\Enums\ApplicationStage;
use App\Models\ApplicationDocument;
use App\Models\Candidate;
use App\Models\JobApplication;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\RecruitmentPermission;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deleting what we no longer have a reason to keep.
 *
 * The decision this class refuses to make: how long that is. There is no
 * default retention period in this module, because the right answer depends on
 * Nigerian employment and data-protection obligations that nobody here is in a
 * position to assert, and a wrong guess either destroys records somebody is
 * required to hold or keeps a rejected applicant's CV for years with no basis.
 * So `recruitment.retention.months` ships unset, the automatic purge ships off,
 * and both must be set deliberately by the owner before anything is deleted on
 * a schedule. Until then the manual path below still works: a specific person's
 * records can always be removed on request.
 *
 * What a purge does:
 *
 *   - The private files are deleted from disk, not just their rows. A purge
 *     that leaves the CV on the volume has deleted nothing that matters.
 *   - The candidate is anonymised rather than deleted outright when they still
 *     have another live application, so removing one application's records can
 *     never break another's.
 *   - The audit trail keeps that a purge happened, by whom, and how many rows
 *     — never the name, the address or any of the answers. An audit log that
 *     preserves the content of a deletion has not honoured it.
 *
 * One limitation that must be stated rather than hidden: database backups
 * taken before a purge still contain the data until those backups age out on
 * the host's own cycle. Deletion here is deletion from the live system.
 */
class RetentionService
{
    /** Hired applications are never swept automatically. */
    private const NEVER_AUTOMATIC = [ApplicationStage::Hired];

    /** The configured period, or null when the owner has not set one. */
    public function months(): ?int
    {
        $configured = Settings::int('recruitment_retention_months', 0)
            ?: (int) config('recruitment.retention.months', 0);

        return $configured > 0 ? $configured : null;
    }

    /** Is the scheduled purge both configured and switched on? */
    public function automaticEnabled(): bool
    {
        if ($this->months() === null) {
            return false;
        }

        return Settings::bool('recruitment_retention_auto', (bool) config('recruitment.retention.auto_purge', false));
    }

    /** Why the scheduled purge is not running, in words for an admin screen. */
    public function blockedReason(): ?string
    {
        if ($this->months() === null) {
            return 'No retention period has been approved yet. Nothing is deleted automatically '
                . 'until somebody sets one, because this module will not choose a legal '
                . 'retention period on your behalf.';
        }

        if (! $this->automaticEnabled()) {
            return 'A retention period is set, but the automatic purge is switched off. '
                . 'Records are only removed when somebody does it by hand.';
        }

        return null;
    }

    /**
     * Applications old enough to sweep.
     *
     * "Old enough" is measured from the last thing that happened to the
     * application, not from when it arrived: a candidate interviewed six
     * months after applying has a six-month-newer record. Active applications
     * are never eligible, and neither is anybody who was hired.
     */
    public function eligible(?int $months = null): \Illuminate\Database\Eloquent\Builder
    {
        $months = $months ?? $this->months();

        if ($months === null) {
            // An impossible query rather than a silent sweep of everything.
            return JobApplication::query()->whereRaw('1 = 0');
        }

        $cutoff = CarbonImmutable::now()->subMonths($months);

        return JobApplication::query()
            ->whereNotIn('stage', array_map(
                fn (ApplicationStage $stage) => $stage->value,
                self::NEVER_AUTOMATIC,
            ))
            ->whereIn('stage', [
                ApplicationStage::Rejected->value,
                ApplicationStage::Withdrawn->value,
            ])
            ->where(function ($query) use ($cutoff) {
                $query->where('stage_changed_at', '<=', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('stage_changed_at')
                        ->where('applied_at', '<=', $cutoff));
            });
    }

    /**
     * What a purge would do, without doing it.
     *
     * The admin screen shows this first. Nothing in this module deletes
     * anything a person has not been shown the count of.
     *
     * @return array{months: ?int, applications: int, candidates: int, documents: int, oldest: ?string}
     */
    public function preview(?int $months = null): array
    {
        $months = $months ?? $this->months();
        $query  = $this->eligible($months);

        $ids = $query->clone()->pluck('id');

        return [
            'months'       => $months,
            'applications' => $ids->count(),
            'candidates'   => $ids->isEmpty() ? 0 : JobApplication::query()
                ->whereIn('id', $ids)
                ->distinct()
                ->count('candidate_id'),
            'documents'    => $ids->isEmpty() ? 0 : ApplicationDocument::query()
                ->whereIn('job_application_id', $ids)
                ->count(),
            'oldest'       => $query->clone()
                ->orderBy('stage_changed_at')
                ->value('stage_changed_at'),
        ];
    }

    /**
     * Remove one application's records, files included.
     *
     * Ordered deliberately: the files go first, because a row deleted before
     * its file leaves bytes on disk with nothing pointing at them, and nobody
     * will ever find them again.
     */
    public function purgeApplication(JobApplication $application, ?User $actor, string $reason): void
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::MANAGE_RETENTION)) {
            throw new RuntimeException('Not authorized to delete recruitment records.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Deleting records needs a reason.');
        }

        $this->purge($application, $actor, $reason);
    }

    /** The purge itself, with no permission check: callers have done that. */
    private function purge(JobApplication $application, ?User $actor, string $reason): void
    {
        $candidateId = $application->candidate_id;
        $reference   = (string) $application->reference;
        $documents   = 0;

        foreach ($application->documents()->withTrashed()->get() as $document) {
            $document->purge();
            $documents++;
        }

        DB::transaction(function () use ($application) {
            // Children first, then the application. Most of these cascade on
            // delete at the database level, but a soft-deleting model would
            // survive that, so each is removed explicitly.
            foreach ($application->assignments as $assignment) {
                $assignment->responses()->forceDelete();
                $assignment->scores()->forceDelete();
                $assignment->forceDelete();
            }

            $application->answers()->forceDelete();
            $application->scores()->forceDelete();
            $application->reviews()->forceDelete();
            $application->notes()->forceDelete();
            $application->stageEvents()->forceDelete();
            $application->interviews()->forceDelete();
            $application->offers()->forceDelete();
            $application->onboardingItems()->forceDelete();
            $application->referenceContacts()->forceDelete();
            $application->messages()->forceDelete();

            $application->forceDelete();
        });

        // Deliberately minimal: a count and a reference, never a name, an
        // address, an answer or the reviewer's words. The point of a purge is
        // that the content is gone, and an audit row that kept it would make
        // the purge a lie.
        AuditLogger::record('recruitment_retention.purged', 'application', null, [
            'reference' => $reference,
            'documents' => $documents,
            'reason'    => mb_substr($reason, 0, 120),
        ], $actor?->id);

        if ($candidateId) {
            $this->tidyCandidate($candidateId, $actor);
        }
    }

    /**
     * After a purge, deal with the candidate record itself.
     *
     * A candidate with another application still on file is left alone: they
     * are a live applicant and deleting them would break the application we
     * are keeping. A candidate with nothing left is anonymised rather than
     * deleted, because the unique index on email_canonical is also what stops
     * a repeat application being treated as a new person, and an empty shell
     * with no personal details in it costs nothing and breaks nothing.
     */
    private function tidyCandidate(int $candidateId, ?User $actor): void
    {
        $candidate = Candidate::query()->find($candidateId);

        if (! $candidate || $candidate->isAnonymised()) {
            return;
        }

        if ($candidate->applications()->exists()) {
            return;
        }

        $this->anonymise($candidate, $actor);
    }

    /**
     * Strip a candidate record of everything that identifies a person.
     *
     * The canonical address is replaced with an unusable placeholder rather
     * than emptied, so the unique index still holds and a future applicant
     * with the same address is a new record rather than a collision with a
     * ghost.
     */
    public function anonymise(Candidate $candidate, ?User $actor): Candidate
    {
        DB::transaction(function () use ($candidate) {
            $candidate->loginTokens()->forceDelete();

            $candidate->forceFill([
                'full_name'                       => 'Removed on request',
                'email'                           => 'removed+' . $candidate->id . '@invalid.local',
                'email_canonical'                 => 'removed+' . $candidate->id . '@invalid.local',
                'phone'                           => null,
                'country'                         => null,
                'city'                            => null,
                'timezone'                        => null,
                'future_opportunities_consent'    => false,
                'future_opportunities_consent_at' => null,
                'remember_token'                  => null,
                'anonymised_at'                   => now(),
            ])->save();
        });

        // The candidate id is the whole record of what happened here. Not even
        // a masked address: the point was to remove it.
        AuditLogger::record('recruitment_retention.anonymised', 'candidate', $candidate->id, [], $actor?->id);

        return $candidate->fresh();
    }

    /**
     * A candidate asking for their own records to be removed.
     *
     * Routed through the same purge rather than a softer path, so "delete my
     * data" means the same thing whoever asks. A candidate in the middle of a
     * live process is told to withdraw first: deleting an application somebody
     * is still interviewing for would strand the interview and the offer.
     */
    public function purgeAtCandidateRequest(Candidate $candidate, ?User $actor = null): int
    {
        $purged = 0;

        foreach ($candidate->applications()->get() as $application) {
            if ($application->isActive()) {
                throw new RuntimeException(
                    'This candidate still has an application in progress (' . $application->reference
                    . '). It has to be withdrawn or closed before the records can be removed.',
                );
            }

            $this->purge($application, $actor, 'Requested by the candidate');
            $purged++;
        }

        $fresh = $candidate->fresh();

        if ($fresh && ! $fresh->isAnonymised()) {
            $this->anonymise($fresh, $actor);
        }

        return $purged;
    }

    /**
     * The scheduled sweep.
     *
     * Returns zero and does nothing at all unless a period has been approved
     * and the automatic purge has been switched on. Both are off as shipped.
     */
    public function runAutomatic(int $limit = 50): int
    {
        if (! $this->automaticEnabled()) {
            return 0;
        }

        $purged = 0;

        foreach ($this->eligible()->limit($limit)->get() as $application) {
            $this->purge($application, null, 'Automatic retention purge');
            $purged++;
        }

        if ($purged > 0) {
            AuditLogger::record('recruitment_retention.swept', 'retention', null, [
                'applications' => $purged,
                'months'       => $this->months(),
            ]);
        }

        return $purged;
    }
}
