<?php

namespace App\Models;

use App\Support\WorkingCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One candidate's copy of one assessment.
 *
 * `template_snapshot` is the whole point. It holds the tasks, the rubric, the
 * brief and the scale exactly as they were when the invitation went out. An
 * administrator improving the template tomorrow changes nothing here, so
 * nobody's exercise shifts under them mid-answer and no reviewer ends up
 * marking against a rubric that did not exist when the work was done.
 *
 * `status` carries OVERDUE, which is deliberately not an application stage. A
 * deadline passing is a fact about this assessment, not a decision about the
 * person; if it were a stage, somebody who submitted an hour late would have
 * to be moved backwards, losing the fact that they submitted at all.
 */
class AssessmentAssignment extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const INVITED   = 'invited';
    public const SUBMITTED = 'submitted';
    public const OVERDUE   = 'overdue';
    public const REOPENED  = 'reopened';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::INVITED   => 'Invited',
        self::SUBMITTED => 'Submitted',
        self::OVERDUE   => 'Overdue',
        self::REOPENED  => 'Reopened for changes',
        self::CANCELLED => 'Cancelled',
    ];

    protected function casts(): array
    {
        return [
            'template_snapshot' => 'array',
            'invited_at'        => 'datetime',
            'due_at'            => 'datetime',
            'original_due_at'   => 'datetime',
            'extended_at'       => 'datetime',
            'submitted_at'      => 'datetime',
            'reopened_at'       => 'datetime',
            'cancelled_at'      => 'datetime',
            'reminder_sent_at'  => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AssessmentTemplate::class, 'assessment_template_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AssessmentResponse::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(ReviewerScore::class);
    }

    /* ---- state --------------------------------------------------------- */

    /**
     * Still this candidate's live assignment.
     *
     * OVERDUE belongs in this list. It is a flag the sweep writes on a live
     * assignment, not an ending: nothing has been submitted, nothing has been
     * cancelled, and a reviewer can still extend it or reopen it. Leaving it
     * out made the state stop being reported at the exact moment the sweep
     * confirmed it — isOverdue() went quiet, the status pill fell back to
     * "Invited", and a new invitation would not have cancelled the old row.
     *
     * Whether she may still TYPE is a different question, and acceptsChanges()
     * is the one that answers it.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::INVITED, self::REOPENED, self::OVERDUE], true)
            && $this->submitted_at === null
            && $this->cancelled_at === null;
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null && $this->status === self::SUBMITTED;
    }

    /**
     * Past the deadline and not submitted.
     *
     * Computed rather than read off the column, because the column is only as
     * current as the last time the scheduler ran — a deadline that passed an
     * hour ago is overdue whether or not the sweep has been round yet. The
     * stored OVERDUE status agrees with this rather than replacing it.
     */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * May the candidate still change their answers?
     *
     * A submitted assessment is read-only. Reopening it is a reviewer's
     * deliberate act, recorded with a reason — otherwise a candidate could
     * quietly revise work that has already been scored.
     */
    public function acceptsChanges(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        // The deadline is enforced on the server. A page left open in a tab
        // overnight does not extend anybody's time.
        return $this->due_at === null || $this->due_at->isFuture();
    }

    /**
     * Why the form is closed, in words for the candidate, or null if it is not.
     *
     * One definition, because three places need this sentence: the page prints
     * it, the draft route repeats it, and the submit route throws it. Three
     * places writing their own is how a missed deadline came to be announced
     * as "this assessment has already been submitted".
     */
    public function closedReason(): ?string
    {
        if ($this->acceptsChanges()) {
            return null;
        }

        if ($this->isSubmitted()) {
            return 'This assessment has already been submitted.';
        }

        if ($this->cancelled_at !== null) {
            return 'This assessment is no longer open.';
        }

        return 'The deadline for this assessment has passed, so the form is closed. '
            . 'Nothing has been decided about your application — reply to the email that '
            . 'invited you if you need more time, and somebody can give you longer.';
    }

    /** The query-side mirror of isOpen(), kept deliberately identical to it. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::INVITED, self::REOPENED, self::OVERDUE])
            ->whereNull('submitted_at')
            ->whereNull('cancelled_at');
    }

    /* ---- the snapshot --------------------------------------------------- */

    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        return (array) ($this->template_snapshot['tasks'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function rubric(): array
    {
        return (array) ($this->template_snapshot['rubric'] ?? []);
    }

    public function brief(): string
    {
        return (string) ($this->template_snapshot['brief'] ?? '');
    }

    public function scaleMax(): int
    {
        return (int) ($this->template_snapshot['score_scale_max'] ?? 5);
    }

    public function effortLine(): ?string
    {
        $minutes = array_values(array_filter((array) ($this->template_snapshot['effort_minutes'] ?? [])));

        return match (count($minutes)) {
            0       => null,
            1       => 'about ' . $minutes[0] . ' minutes',
            default => 'about ' . $minutes[0] . '-' . $minutes[1] . ' minutes',
        };
    }

    /* ---- the deadline, in words ---------------------------------------- */

    /** The deadline written with its time zone named, never without. */
    public function dueForHumans(?string $timezone = null): ?string
    {
        return $this->due_at ? WorkingCalendar::forHumans($this->due_at, $timezone) : null;
    }

    public function wasExtended(): bool
    {
        return (int) $this->extension_count > 0;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->isOverdue() ? self::OVERDUE : $this->status] ?? 'Invited';
    }

    /** The answers a reviewer marks: the final revision of each task. */
    public function finalAnswers(): \Illuminate\Support\Collection
    {
        return $this->responses()
            ->where('is_final', true)
            ->orderByDesc('revision')
            ->get()
            ->unique('task_key')
            ->keyBy('task_key');
    }

    /** The newest draft of each task, for the candidate's own form. */
    public function draftAnswers(): \Illuminate\Support\Collection
    {
        return $this->responses()
            ->orderByDesc('revision')
            ->get()
            ->unique('task_key')
            ->keyBy('task_key');
    }
}
