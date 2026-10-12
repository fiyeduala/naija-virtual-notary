<?php

namespace App\Models;

use App\Enums\RecruitmentRole;
use App\Enums\VacancyState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An opening.
 *
 * The important method here is publicationBlockers(). A vacancy goes on the
 * public site only when it can answer the questions a candidate will ask, and
 * one of those questions is about pay — so an administrator has to state
 * whether pay is disclosed before the page can go live. They may say "not
 * disclosed"; they may not say nothing, because a blank salary that silently
 * publishes is this application deciding a policy on their behalf.
 *
 * Nothing in this class invents a salary, a headcount, an employment type or a
 * commission rate. Every one of those columns is nullable and renders as
 * "to be confirmed" on the admin screen until a person fills it in.
 */
class Vacancy extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const SALARY_DISCLOSED   = 'disclosed';
    public const SALARY_UNDISCLOSED = 'undisclosed';

    public const SALARY_DISCLOSURE = [
        self::SALARY_DISCLOSED   => 'Show the salary range on the advert',
        self::SALARY_UNDISCLOSED => 'Do not show pay on the advert',
    ];

    public const PAY_PERIODS = [
        'month' => 'per month',
        'year'  => 'per year',
        'hour'  => 'per hour',
    ];

    public const COMPENSATION_TYPES = [
        'fixed'      => 'Fixed pay',
        'commission' => 'Commission only',
        'mixed'      => 'Fixed pay plus commission',
    ];

    /**
     * What to do with candidates still in the pipeline when a vacancy closes.
     *
     * Recorded as a choice because closing an advert and ending somebody's
     * candidacy are two different acts, and a system that quietly does the
     * second when you asked for the first is one that writes rejections you
     * never approved.
     */
    public const CLOSE_KEEP   = 'keep_candidates';
    public const CLOSE_REJECT = 'reject_remaining';

    public const CLOSE_ACTIONS = [
        self::CLOSE_KEEP   => 'Keep existing candidates in the pipeline',
        self::CLOSE_REJECT => 'Also decline everyone still in progress',
    ];

    protected function casts(): array
    {
        return [
            'state'                       => VacancyState::class,
            'role_key'                    => RecruitmentRole::class,
            'screening_questions'         => 'array',
            'intro_video_enabled'         => 'boolean',
            'intro_video_required'        => 'boolean',
            'guarantors_required'         => 'boolean',
            'identity_documents_required' => 'boolean',
            'opens_at'                    => 'datetime',
            'closes_at'                   => 'datetime',
            'published_at'                => 'datetime',
            'closed_at'                   => 'datetime',
        ];
    }

    /* ---- relations ----------------------------------------------------- */

    public function template(): BelongsTo
    {
        return $this->belongsTo(AssessmentTemplate::class, 'assessment_template_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function reviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vacancy_reviewers')->withTimestamps();
    }

    public function hiringLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_lead_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /* ---- scopes -------------------------------------------------------- */

    /** What /careers lists. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('state', VacancyState::Published->value);
    }

    /** What a direct link may render, closed adverts included. */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('state', [
            VacancyState::Published->value,
            VacancyState::Paused->value,
            VacancyState::Closed->value,
        ]);
    }

    /* ---- the server-side gate ------------------------------------------ */

    /**
     * May this vacancy accept a submission right now?
     *
     * Every path that creates an application asks this. The Apply button being
     * absent is a courtesy to the visitor; this is the actual rule, and a
     * hand-crafted POST to the store route meets it just the same.
     */
    public function acceptsApplications(): bool
    {
        if ($this->state !== VacancyState::Published) {
            return false;
        }

        if ($this->opens_at && $this->opens_at->isFuture()) {
            return false;
        }

        if ($this->closes_at && $this->closes_at->isPast()) {
            return false;
        }

        return true;
    }

    /** Why it is not accepting, in words a visitor can act on. */
    public function closureReason(): ?string
    {
        if ($this->acceptsApplications()) {
            return null;
        }

        $zone = config('recruitment.timezone');

        return match (true) {
            $this->state === VacancyState::Paused => 'Applications for this role are paused for now. The advert will reopen here if that changes.',
            $this->state === VacancyState::Closed => 'Applications for this role have closed.',
            (bool) $this->closes_at?->isPast()    => 'Applications for this role closed on ' . $this->closes_at->timezone($zone)->format('j F Y') . '.',
            (bool) $this->opens_at?->isFuture()   => 'Applications for this role open on ' . $this->opens_at->timezone($zone)->format('j F Y') . '.',
            default                               => 'This role is not accepting applications at the moment.',
        };
    }

    /**
     * Everything that must be settled before this can be published.
     *
     * Returned as a list of sentences rather than a boolean so the panel can
     * show an administrator precisely what is outstanding. The salary entry is
     * the one people are surprised by, and it is deliberate: choosing NOT to
     * disclose pay is a decision, and an unmade decision is not the same as a
     * decision to stay quiet.
     *
     * @return list<string>
     */
    public function publicationBlockers(): array
    {
        $missing = [];

        if (blank($this->summary) && blank($this->responsibilities)) {
            $missing[] = 'A description: at least the overview and what the person will be doing.';
        }

        if (blank($this->requirements)) {
            $missing[] = 'What you are looking for (the requirements).';
        }

        if (blank($this->employment_type)) {
            $missing[] = 'The employment type, e.g. full-time or part-time. Nothing here guesses it.';
        }

        if (blank($this->location_eligibility)) {
            $missing[] = 'Which countries someone must be able to work from. Remote is not the same as worldwide.';
        }

        if (blank($this->working_hours)) {
            $missing[] = 'The hours the person must be available, and the time zone they are quoted in.';
        }

        if (! $this->closes_at) {
            $missing[] = 'A closing date.';
        }

        if (blank($this->salary_disclosure)) {
            $missing[] = 'Whether pay is shown on the advert. Either choice is fine; leaving it unanswered is not.';
        }

        if ($this->salary_disclosure === self::SALARY_DISCLOSED
            && (blank($this->salary_min_minor) || blank($this->salary_currency) || blank($this->pay_period))) {
            $missing[] = 'You chose to show pay, so the amount, the currency and the period are all needed.';
        }

        if ($this->guarantors_required && blank($this->guarantor_reason)) {
            $missing[] = 'You are asking candidates for a guarantor, so say why this role needs one.';
        }

        if (! $this->assessment_template_id) {
            $missing[] = 'An assessment template, so invited candidates have something to complete.';
        }

        return $missing;
    }

    public function canBePublished(): bool
    {
        return $this->publicationBlockers() === [];
    }

    /* ---- display -------------------------------------------------------- */

    /** The pay line on the advert, or null when pay is not disclosed. */
    public function salaryLine(): ?string
    {
        if ($this->salary_disclosure !== self::SALARY_DISCLOSED || blank($this->salary_min_minor)) {
            return null;
        }

        $currency = strtoupper((string) $this->salary_currency);
        $symbol   = match ($currency) {
            'NGN'   => '₦',
            'USD'   => '$',
            'GBP'   => '£',
            default => $currency . ' ',
        };

        $format = fn (?int $minor) => $minor === null ? null : $symbol . number_format($minor / 100);

        $low  = $format((int) $this->salary_min_minor);
        $high = $this->salary_max_minor ? $format((int) $this->salary_max_minor) : null;
        $per  = self::PAY_PERIODS[$this->pay_period] ?? '';

        return trim(($high && $high !== $low ? $low . ' - ' . $high : $low) . ' ' . $per);
    }

    /**
     * The compensation note, shown only when it was actually configured.
     *
     * A commission-based role says so and says whatever the office wrote; it
     * never carries a percentage this application made up.
     */
    public function compensationLine(): ?string
    {
        $label = self::COMPENSATION_TYPES[$this->compensation_type] ?? null;

        if (! $label) {
            return null;
        }

        return filled($this->compensation_note)
            ? $label . ' - ' . $this->compensation_note
            : $label;
    }

    /** Working days a candidate gets for the assessment on this vacancy. */
    public function assessmentDueWorkingDays(): int
    {
        return (int) ($this->assessment_due_working_days
            ?? config('recruitment.assessment.due_working_days', 3));
    }

    /**
     * The screening questions, normalised.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options: list<string>}>
     */
    public function questions(): array
    {
        $questions = [];

        foreach ((array) ($this->screening_questions ?? []) as $index => $question) {
            if (! is_array($question) || blank($question['label'] ?? null)) {
                continue;
            }

            $questions[] = [
                'key'      => (string) ($question['key'] ?? 'q' . ($index + 1)),
                'label'    => (string) $question['label'],
                'type'     => (string) ($question['type'] ?? 'long_text'),
                'required' => (bool) ($question['required'] ?? false),
                'options'  => array_values(array_filter(array_map(
                    fn ($option) => is_string($option) ? trim($option) : null,
                    (array) ($question['options'] ?? []),
                ))),
            ];
        }

        return $questions;
    }

    public function roleLabel(): string
    {
        return $this->role_key instanceof RecruitmentRole
            ? $this->role_key->label()
            : (string) $this->role_key;
    }

    public function departmentLabel(): string
    {
        if (filled($this->department)) {
            return (string) $this->department;
        }

        return $this->role_key instanceof RecruitmentRole
            ? $this->role_key->department()
            : '';
    }
}
