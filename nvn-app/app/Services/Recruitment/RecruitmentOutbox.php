<?php

namespace App\Services\Recruitment;

use App\Mail\CandidateMail;
use App\Models\Candidate;
use App\Models\JobApplication;
use App\Models\Offer;
use App\Models\RecruitmentMessage;
use App\Support\AuditLogger;
use App\Support\EmailNormalizer;
use App\Support\RecruitmentTemplates;
use App\Support\WorkingCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only way a candidate email leaves this module.
 *
 * Writing a row is cheap and happens inside whatever transaction justified the
 * email; sending happens later, from the per-minute cron. That ordering is the
 * whole point — see App\Models\RecruitmentMessage for why.
 *
 * The second job of this class is refusing to send. A message queued on Monday
 * for Thursday describes a world that may have changed: the candidate may have
 * withdrawn, a reviewer may have rejected them, the deadline may have been
 * extended, the interview may have moved. So every message is re-checked
 * against the live records immediately before it goes, and one that no longer
 * makes sense is cancelled rather than sent. A wrong email to a candidate
 * cannot be recalled.
 */
class RecruitmentOutbox
{
    /**
     * Put a message in the outbox. Idempotent on the event key.
     *
     * Returns the existing row when one is already there, so a double-clicked
     * button, a retried job and two reviewers pressing the same thing produce
     * one email between them.
     *
     * @param  array<string, mixed>  $options  event_key, available_at, payload, data, footnote, ics
     */
    public function queue(string $templateKey, JobApplication $application, array $options = []): RecruitmentMessage
    {
        $eventKey = (string) ($options['event_key'] ?? $this->eventKey($templateKey, $application));

        $candidate = $application->candidate;
        $email     = EmailNormalizer::clean((string) ($candidate->email ?? ''));

        $payload = array_filter([
            'data'         => $options['data'] ?? [],
            'action_url'   => $options['action_url'] ?? null,
            'footnote'     => $options['footnote'] ?? null,
            'ics'          => $options['ics'] ?? null,
            'ics_filename' => $options['ics_filename'] ?? null,
            'guard'        => $options['guard'] ?? [],
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');

        return RecruitmentMessage::firstOrCreate(
            ['event_key' => Str::limit($eventKey, 185, '')],
            [
                'job_application_id' => $application->id,
                'candidate_id'       => $application->candidate_id,
                'mailable'           => CandidateMail::class,
                'template_key'       => $templateKey,
                'recipient_email'    => $email,
                'recipient_name'     => (string) ($candidate->full_name ?? ''),
                'payload'            => $payload,
                'status'             => RecruitmentMessage::PENDING,
                'available_at'       => $options['available_at'] ?? now(),
                'attempts'           => 0,
            ],
        );
    }

    /**
     * The default key: one of each kind per application.
     *
     * Callers that legitimately send more than one — a reschedule, a reminder
     * on an extended deadline — pass their own key with the thing that makes it
     * different in it.
     */
    public function eventKey(string $templateKey, JobApplication $application, string ...$parts): string
    {
        return implode(':', array_filter([$templateKey, (string) $application->id, ...$parts]));
    }

    /**
     * Send everything that is due. Called by nvn:recruitment-dispatch.
     *
     * @return array{sent: int, failed: int, cancelled: int}
     */
    public function dispatchDue(int $limit = 25): array
    {
        $tally = ['sent' => 0, 'failed' => 0, 'cancelled' => 0];

        $ids = RecruitmentMessage::query()->due()->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            // One row at a time, claimed under a lock, so two overlapping cron
            // runs cannot both send the same email.
            $message = DB::transaction(function () use ($id) {
                $row = RecruitmentMessage::query()->whereKey($id)->lockForUpdate()->first();

                if (! $row || $row->status !== RecruitmentMessage::PENDING) {
                    return null;
                }

                $row->forceFill([
                    'attempts'      => $row->attempts + 1,
                    'dispatched_at' => now(),
                ])->save();

                return $row;
            });

            if (! $message) {
                continue;
            }

            $blocked = $this->obsoleteReason($message);

            if ($blocked !== null) {
                $this->cancel($message, $blocked);
                $tally['cancelled']++;

                continue;
            }

            $this->send($message) ? $tally['sent']++ : $tally['failed']++;
        }

        return $tally;
    }

    /** Hand one message to the mail server. */
    public function send(RecruitmentMessage $message): bool
    {
        if (! filter_var($message->recipient_email, FILTER_VALIDATE_EMAIL)) {
            $this->fail($message, 'The recorded address is not a usable email address.');

            return false;
        }

        try {
            Mail::to($message->recipient_email, $message->recipient_name ?: null)
                ->send(new CandidateMail($message));

            $message->forceFill([
                'status'     => RecruitmentMessage::SENT,
                'sent_at'    => now(),
                'last_error' => null,
            ])->save();

            return true;
        } catch (Throwable $e) {
            // The message itself is never logged: it carries the candidate's
            // name and their sign-in link. Only the row id, the template and
            // the failure reach the log.
            Log::warning('Recruitment email failed', [
                'message_id' => $message->id,
                'template'   => $message->template_key,
                'error'      => Str::limit($e->getMessage(), 200),
            ]);

            $this->fail($message, $e->getMessage());

            return false;
        }
    }

    /** A person pressing "try again" in the panel. Never on a sent message. */
    public function retry(RecruitmentMessage $message): bool
    {
        if (! $message->isRetryable()) {
            return false;
        }

        $message->forceFill([
            'status'       => RecruitmentMessage::PENDING,
            'attempts'     => 0,
            'available_at' => now(),
            'failed_at'    => null,
            'cancelled_at' => null,
        ])->save();

        return true;
    }

    /**
     * Call off everything of these kinds that has not gone out yet.
     *
     * Used when a stage change makes queued messages wrong — a withdrawal, a
     * rejection, a reschedule, a cancelled assessment.
     *
     * @param  list<string>  $templateKeys  empty means every kind
     */
    public function cancelPending(JobApplication $application, array $templateKeys, string $reason): int
    {
        $query = RecruitmentMessage::query()
            ->where('job_application_id', $application->id)
            ->where('status', RecruitmentMessage::PENDING);

        if ($templateKeys !== []) {
            $query->whereIn('template_key', $templateKeys);
        }

        return $query->update([
            'status'           => RecruitmentMessage::CANCELLED,
            'cancelled_at'     => now(),
            'cancelled_reason' => Str::limit($reason, 190, ''),
            'updated_at'       => now(),
        ]);
    }

    /**
     * Why this message must not be sent, or null if it still should be.
     *
     * Read this as the list of things that can change between queueing an
     * email and sending it. Each clause is a candidate who would otherwise
     * receive something untrue.
     */
    public function obsoleteReason(RecruitmentMessage $message): ?string
    {
        // A message with no application behind it is the sign-in link, and
        // there is nothing about it that can go stale.
        if ($message->job_application_id === null) {
            return null;
        }

        $application = $message->application;

        if (! $application) {
            return 'The application no longer exists.';
        }

        $key = (string) $message->template_key;

        // A rejection and a withdrawal confirmation are ABOUT the terminal
        // state, so they are the two that must still go out in it.
        $terminalOk = [RecruitmentTemplates::REJECTED, RecruitmentTemplates::WITHDRAWN];

        if (! in_array($key, $terminalOk, true)) {
            if ($application->isWithdrawn()) {
                return 'The candidate withdrew.';
            }

            if ($application->isRejected()) {
                return 'The application was rejected.';
            }
        }

        if ($key === RecruitmentTemplates::REJECTED && ! $application->isRejected()) {
            return 'The application is no longer rejected.';
        }

        if ($key === RecruitmentTemplates::WITHDRAWN && ! $application->isWithdrawn()) {
            return 'The application is no longer withdrawn.';
        }

        return $this->guardFailed($message, $application);
    }

    /**
     * The per-message guard, carried in the payload when it was queued.
     *
     * `assignment` — an invitation or reminder belongs to one assessment
     * assignment and must not go if that assignment was cancelled or already
     * submitted, or (for a reminder) if the deadline has already passed.
     * `interview` plus `interview_sequence` — an invitation must not go if the
     * interview was cancelled or has been moved again since; a reschedule
     * queues a newer message carrying a newer sequence.
     * `offer` — an offer email must not go if the offer was withdrawn.
     */
    private function guardFailed(RecruitmentMessage $message, JobApplication $application): ?string
    {
        $guard = (array) (($message->payload ?? [])['guard'] ?? []);

        if ($id = ($guard['assignment'] ?? null)) {
            $assignment = $application->assignments()->whereKey($id)->first();

            if (! $assignment || $assignment->cancelled_at) {
                return 'The assessment was cancelled.';
            }

            if ($assignment->isSubmitted()) {
                return 'The candidate has already submitted.';
            }

            if (($guard['before_due'] ?? false) && $assignment->due_at && $assignment->due_at->isPast()) {
                return 'The deadline has already passed.';
            }
        }

        if ($id = ($guard['interview'] ?? null)) {
            $interview = $application->interviews()->whereKey($id)->first();

            if (! $interview || $interview->isCancelled()) {
                return 'The interview was cancelled.';
            }

            if (isset($guard['interview_sequence'])
                && (int) $interview->ics_sequence !== (int) $guard['interview_sequence']) {
                return 'The interview has been rescheduled since.';
            }
        }

        if ($id = ($guard['offer'] ?? null)) {
            $offer = $application->offers()->whereKey($id)->first();

            if (! $offer || $offer->status === Offer::WITHDRAWN) {
                return 'The offer was withdrawn.';
            }
        }

        return null;
    }

    private function cancel(RecruitmentMessage $message, string $reason): void
    {
        $message->forceFill([
            'status'           => RecruitmentMessage::CANCELLED,
            'cancelled_at'     => now(),
            'cancelled_reason' => Str::limit($reason, 190, ''),
        ])->save();

        AuditLogger::record('recruitment_message.cancelled', 'recruitment_message', $message->id, [
            'template' => $message->template_key,
            'reason'   => $reason,
        ]);
    }

    private function fail(RecruitmentMessage $message, string $error): void
    {
        $message->forceFill([
            'status'     => RecruitmentMessage::FAILED,
            'failed_at'  => now(),
            'last_error' => Str::limit($error, 480),
        ])->save();
    }

    /** The data every template can use. Deadlines are added by the caller. */
    public function baseData(JobApplication $application): array
    {
        $candidate = $application->candidate;

        return [
            'first_name' => $candidate?->firstName() ?: 'there',
            'full_name'  => (string) ($candidate->full_name ?? ''),
            'role'       => (string) ($application->vacancy->title ?? 'the role'),
            'reference'  => (string) $application->reference,
            'company'    => 'Naija Virtual Notary',
        ];
    }

    /** A deadline, written the way candidate emails write deadlines. */
    public function deadlineFor(JobApplication $application, mixed $at): string
    {
        return WorkingCalendar::forHumans(
            \Carbon\CarbonImmutable::parse($at),
            $application->candidate?->timezone ?: null,
        );
    }

    /**
     * A message to a candidate that belongs to no single application.
     *
     * Only the sign-in link uses this. `job_application_id` stays null, so the
     * obsolescence check has nothing to re-read and the message goes as queued
     * — which is right: a link somebody just asked for cannot become wrong in
     * the minute before it is sent, and it expires on its own.
     *
     * @param  array<string, mixed>  $options
     */
    public function queueToCandidate(string $templateKey, Candidate $candidate, array $options = []): RecruitmentMessage
    {
        $eventKey = (string) ($options['event_key'] ?? implode(':', [
            $templateKey,
            'c' . $candidate->id,
            (string) now()->getTimestampMs(),
        ]));

        return RecruitmentMessage::create([
            'event_key'       => Str::limit($eventKey, 185, ''),
            'candidate_id'    => $candidate->id,
            'mailable'        => CandidateMail::class,
            'template_key'    => $templateKey,
            'recipient_email' => EmailNormalizer::clean((string) $candidate->email),
            'recipient_name'  => (string) $candidate->full_name,
            'payload'         => array_filter([
                'data'       => $options['data'] ?? ['company' => 'Naija Virtual Notary'],
                'action_url' => $options['action_url'] ?? null,
                'footnote'   => $options['footnote'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'status'          => RecruitmentMessage::PENDING,
            'available_at'    => now(),
            'attempts'        => 0,
        ]);
    }
}
