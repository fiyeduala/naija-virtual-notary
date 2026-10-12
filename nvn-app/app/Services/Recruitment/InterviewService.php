<?php

namespace App\Services\Recruitment;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ExternalLink;
use App\Support\IcsBuilder;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Booking, moving and recording interviews.
 *
 * The meeting link is typed in by whoever is holding the interview. Nothing
 * here provisions a Google Meet or a Zoom room — that needs an OAuth
 * integration per interviewer and is out of scope for this release, which the
 * admin screen says plainly rather than leaving an empty field looking broken.
 *
 * The part worth reading carefully is the calendar identity. One interview has
 * one UID for its whole life, and rescheduling increments SEQUENCE. That is
 * what makes a candidate's calendar MOVE the existing entry instead of adding
 * a second one next to it — and a candidate who shows up at the old time
 * because they got two invitations is a failure of this class, not of theirs.
 */
class InterviewService
{
    public function __construct(
        private RecruitmentOutbox $outbox,
        private ApplicationStageService $stages,
    ) {}

    /**
     * @param  array{scheduled_at: mixed, duration_minutes?: int, timezone?: ?string, interviewer_id?: ?int, meeting_url?: ?string, candidate_instructions?: ?string, round?: ?int, notify?: bool}  $data
     *
     * @throws StageTransitionException
     */
    public function schedule(JobApplication $application, ?User $actor, array $data): Interview
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::SCHEDULE_INTERVIEW)) {
            throw new StageTransitionException('You do not have permission to schedule interviews.');
        }

        if (! $application->isActive()) {
            throw new StageTransitionException('This application is closed, so no interview can be booked.');
        }

        $at = CarbonImmutable::parse($data['scheduled_at']);

        if ($at->isPast()) {
            throw new StageTransitionException('That time is in the past.');
        }

        $url = $this->meetingUrl($data['meeting_url'] ?? null);

        return DB::transaction(function () use ($application, $actor, $data, $at, $url) {
            $round = (int) ($data['round'] ?? ($application->interviews()->max('round') + 1));

            $interview = $application->interviews()->create([
                'round'                  => max(1, $round),
                'scheduled_at'           => $at,
                'duration_minutes'       => max(10, (int) ($data['duration_minutes'] ?? 30)),
                'timezone'               => $data['timezone'] ?: WorkingCalendar::timezone(),
                'interviewer_id'         => $data['interviewer_id'] ?? $actor?->id,
                'meeting_url'            => $url,
                'candidate_instructions' => Str::limit((string) ($data['candidate_instructions'] ?? ''), 4000, '') ?: null,
                'status'                 => Interview::SCHEDULED,
                'ics_sequence'           => 0,
                'created_by'             => $actor?->id,
            ]);

            if ($application->stage !== ApplicationStage::Interview) {
                $this->stages->move($application, ApplicationStage::Interview, $actor, [
                    'skip_permission' => true,
                    'reason'          => $application->stage === ApplicationStage::InitialReview
                        ? 'Invited straight to interview.'
                        : null,
                    'metadata'        => ['interview_id' => $interview->id],
                ]);
            }

            if ($data['notify'] ?? true) {
                $this->queueInvitation($interview, RecruitmentTemplates::INTERVIEW_SENT);
            }

            AuditLogger::record('recruitment_interview.scheduled', 'interview', $interview->id, [
                'application' => $application->reference,
                'round'       => $interview->round,
                'at'          => $at->toIso8601String(),
            ], $actor?->id);

            return $interview->fresh();
        });
    }

    /**
     * Move an interview. Same UID, next SEQUENCE.
     *
     * @param  array{scheduled_at: mixed, duration_minutes?: ?int, timezone?: ?string, meeting_url?: ?string, candidate_instructions?: ?string, notify?: bool}  $data
     *
     * @throws StageTransitionException
     */
    public function reschedule(Interview $interview, ?User $actor, array $data): Interview
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::SCHEDULE_INTERVIEW)) {
            throw new StageTransitionException('You do not have permission to reschedule interviews.');
        }

        if ($interview->isCancelled()) {
            throw new StageTransitionException('This interview was cancelled. Book a new one instead.');
        }

        $at = CarbonImmutable::parse($data['scheduled_at']);

        if ($at->isPast()) {
            throw new StageTransitionException('That time is in the past.');
        }

        return DB::transaction(function () use ($interview, $actor, $data, $at) {
            $was = $interview->scheduled_at;

            $interview->forceFill(array_filter([
                'scheduled_at'             => $at,
                'duration_minutes'         => isset($data['duration_minutes']) ? max(10, (int) $data['duration_minutes']) : null,
                'timezone'                 => $data['timezone'] ?? null,
                'meeting_url'              => isset($data['meeting_url']) ? $this->meetingUrl($data['meeting_url']) : null,
                'candidate_instructions'   => isset($data['candidate_instructions'])
                    ? (Str::limit((string) $data['candidate_instructions'], 4000, '') ?: null)
                    : null,
            ], fn ($v) => $v !== null) + [
                'status'                   => Interview::RESCHEDULED,
                'ics_sequence'             => (int) $interview->ics_sequence + 1,
                // The old acknowledgment was for the old time.
                'candidate_acknowledged_at' => null,
                'reschedule_requested_at'  => null,
            ])->save();

            $interview = $interview->fresh();

            // The earlier invitation, if it has not gone yet, must not go: it
            // names the old time and carries the older SEQUENCE.
            $this->outbox->cancelPending(
                $interview->application,
                [RecruitmentTemplates::INTERVIEW_SENT, RecruitmentTemplates::INTERVIEW_MOVED],
                'The interview was rescheduled.',
            );

            if ($data['notify'] ?? true) {
                $this->queueInvitation($interview, RecruitmentTemplates::INTERVIEW_MOVED);
            }

            AuditLogger::record('recruitment_interview.rescheduled', 'interview', $interview->id, [
                'application' => $interview->application->reference,
                'from'        => $was?->toIso8601String(),
                'to'          => $at->toIso8601String(),
                'sequence'    => $interview->ics_sequence,
            ], $actor?->id);

            return $interview;
        });
    }

    /** Called off. No new email; the candidate is told by a person. */
    public function cancel(Interview $interview, ?User $actor, string $reason): Interview
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::SCHEDULE_INTERVIEW)) {
            throw new StageTransitionException('You do not have permission to cancel interviews.');
        }

        $interview->forceFill([
            'status'       => Interview::CANCELLED,
            'ics_sequence' => (int) $interview->ics_sequence + 1,
        ])->save();

        $this->outbox->cancelPending(
            $interview->application,
            [RecruitmentTemplates::INTERVIEW_SENT, RecruitmentTemplates::INTERVIEW_MOVED],
            'The interview was cancelled.',
        );

        AuditLogger::record('recruitment_interview.cancelled', 'interview', $interview->id, [
            'application' => $interview->application->reference,
            'reason'      => Str::limit($reason, 160),
        ], $actor?->id);

        return $interview->fresh();
    }

    /** Build the invitation, with the calendar file attached. */
    public function queueInvitation(Interview $interview, string $templateKey): void
    {
        $application = $interview->application;
        $candidate   = $application->candidate;

        $ics = IcsBuilder::build([
            'uid'         => $interview->calendarUid(),
            'sequence'    => (int) $interview->ics_sequence,
            'starts_at'   => $interview->scheduled_at,
            'ends_at'     => $interview->endsAt(),
            'summary'     => sprintf('Interview: %s — Naija Virtual Notary', $application->vacancy->title ?? 'role'),
            'description' => trim(sprintf(
                "Interview for the %s role at Naija Virtual Notary.\nReference: %s\n%s",
                $application->vacancy->title ?? 'role',
                $application->reference,
                (string) $interview->candidate_instructions,
            )),
            'location'  => $interview->meeting_url,
            'cancelled'       => $interview->isCancelled(),
            'attendee_email'  => $candidate?->email,
            'attendee_name'   => $candidate?->full_name,
            'organiser_email' => RecruitmentTemplates::replyTo(),
        ]);

        $this->outbox->queue($templateKey, $application, [
            'event_key'  => $this->outbox->eventKey(
                $templateKey,
                $application,
                'i' . $interview->id,
                's' . $interview->ics_sequence,
            ),
            'action_url' => route('candidate.login'),
            'data'       => $this->outbox->baseData($application) + [
                'interview_time' => $interview->timeForHumans($candidate?->timezone),
                'meeting_url'    => $interview->meeting_url ?: 'we will confirm the joining details separately',
            ],
            'ics'          => $ics,
            'ics_filename' => IcsBuilder::filename($application->reference),
            'guard'        => [
                'interview'          => $interview->id,
                'interview_sequence' => (int) $interview->ics_sequence,
            ],
        ]);
    }

    /** The candidate pressing "I will be there". */
    public function acknowledge(Interview $interview): void
    {
        if ($interview->isCancelled() || $interview->candidate_acknowledged_at) {
            return;
        }

        $interview->forceFill(['candidate_acknowledged_at' => now()])->save();
    }

    /** The candidate asking for a different time. Never moves it by itself. */
    public function requestReschedule(Interview $interview, string $note): void
    {
        if ($interview->isCancelled()) {
            return;
        }

        $interview->forceFill([
            'reschedule_requested_at'   => now(),
            'reschedule_request_note'   => Str::limit($note, 1000, ''),
        ])->save();

        AuditLogger::record('recruitment_interview.reschedule_requested', 'interview', $interview->id, [
            'application' => $interview->application->reference,
        ]);
    }

    /**
     * What the interviewer thought. Internal, and never shown to a candidate.
     *
     * @param  array{feedback?: ?string, outcome?: ?string, salary_discussion_note?: ?string, decision_note?: ?string}  $data
     */
    public function recordFeedback(Interview $interview, ?User $actor, array $data): Interview
    {
        if (! RecruitmentPermission::canReview($actor, $interview->application)) {
            throw new StageTransitionException('This application is not assigned to you.');
        }

        $interview->forceFill([
            'feedback'                => isset($data['feedback']) ? Str::limit((string) $data['feedback'], 8000, '') : $interview->feedback,
            'feedback_by'             => $actor?->id,
            'feedback_at'             => now(),
            'outcome'                 => $data['outcome'] ?? $interview->outcome,
            'salary_discussion_note'  => isset($data['salary_discussion_note'])
                ? Str::limit((string) $data['salary_discussion_note'], 4000, '')
                : $interview->salary_discussion_note,
            'decision_note'           => isset($data['decision_note'])
                ? Str::limit((string) $data['decision_note'], 4000, '')
                : $interview->decision_note,
            'status'                  => $interview->status === Interview::CANCELLED
                ? Interview::CANCELLED
                : Interview::COMPLETED,
        ])->save();

        return $interview->fresh();
    }

    /**
     * A link somebody typed, checked before it is put in an email.
     *
     * http(s) only, so nothing with a javascript: or data: scheme reaches a
     * candidate's browser. The link is not fetched, previewed or embedded —
     * it is shown as text and as an href, which is all it needs to be.
     *
     * @throws StageTransitionException
     */
    private function meetingUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $clean = ExternalLink::normalise($url);

        if (! $clean) {
            throw new StageTransitionException('That meeting link does not look like a web address.');
        }

        return $clean;
    }
}
