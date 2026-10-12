<?php

namespace App\Models;

use App\Support\IcsBuilder;
use App\Support\WorkingCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A scheduled conversation.
 *
 * The calendar attachment is the fiddly part, and two columns carry it: the
 * UID is derived from this row's id and never changes, and `ics_sequence` is
 * bumped on every reschedule. Get the first wrong and the candidate's calendar
 * gains a second event instead of moving the first; get the second wrong and
 * their calendar client ignores the update entirely. Either way somebody
 * arrives at the old time, and the system looks like it told them to.
 *
 * `meeting_url` is typed in by a member of staff. Provisioning a Meet or Zoom
 * room is out of scope, and inventing a link would mean sending an invitation
 * to a room that does not exist.
 *
 * `feedback` is internal. No candidate-facing route reads it.
 */
class Interview extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const SCHEDULED   = 'scheduled';
    public const RESCHEDULED = 'rescheduled';
    public const CANCELLED   = 'cancelled';
    public const COMPLETED   = 'completed';
    public const NO_SHOW     = 'no_show';

    public const STATUSES = [
        self::SCHEDULED   => 'Scheduled',
        self::RESCHEDULED => 'Rescheduled',
        self::CANCELLED   => 'Cancelled',
        self::COMPLETED   => 'Completed',
        self::NO_SHOW     => 'Candidate did not attend',
    ];

    public const OUTCOMES = [
        'advance' => 'Take forward',
        'hold'    => 'Hold',
        'decline' => 'Do not take forward',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at'              => 'datetime',
            'candidate_acknowledged_at' => 'datetime',
            'reschedule_requested_at'   => 'datetime',
            'feedback_at'               => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    /** Stable for the life of this interview, across every reschedule. */
    public function calendarUid(): string
    {
        return IcsBuilder::uid((int) $this->id);
    }

    public function endsAt(): ?\Carbon\CarbonInterface
    {
        return $this->scheduled_at?->copy()->addMinutes((int) ($this->duration_minutes ?: 30));
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::SCHEDULED, self::RESCHEDULED], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Scheduled';
    }

    /**
     * The time, written twice: in the office's zone and in the candidate's.
     *
     * A single rendering is how somebody in Toronto turns up six hours late.
     */
    public function timeForHumans(?string $candidateTimezone = null): string
    {
        if (! $this->scheduled_at) {
            return 'Not yet scheduled';
        }

        $office = WorkingCalendar::forHumans($this->scheduled_at);

        if (! $candidateTimezone || $candidateTimezone === WorkingCalendar::timezone()) {
            return $office;
        }

        return $office . ' / ' . WorkingCalendar::forHumans($this->scheduled_at, $candidateTimezone);
    }
}
