<?php

namespace App\Models;

use App\Enums\ApplicationStage;
use App\Support\SourceAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person's application for one vacancy.
 *
 * The unique index on (vacancy_id, candidate_id) is the duplicate rule, and it
 * is in the database rather than in a check-then-insert because two
 * submissions arriving in the same second would both pass a check and both
 * insert. Applying for a DIFFERENT vacancy is allowed and expected, and each
 * application keeps its own answers, its own CV version and its own
 * assessment, so a later application can never overwrite the evidence behind
 * an earlier one.
 *
 * `stage` is never assigned directly. Every move goes through
 * App\Services\Recruitment\ApplicationStageService, which takes a lock,
 * checks the prerequisites, writes a stage event with an actor, and cancels
 * any pending message the move has made obsolete.
 */
class JobApplication extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage'                  => ApplicationStage::class,
            'stage_changed_at'       => 'datetime',
            'applied_at'             => 'datetime',
            'availability_start'     => 'date',
            'available_during_hours' => 'boolean',
            'has_suitable_computer'  => 'boolean',
            'screening_snapshot'     => 'array',
            'privacy_accepted_at'    => 'datetime',
            'rejected_at'            => 'datetime',
            'withdrawn_at'           => 'datetime',
            'hired_at'               => 'datetime',
        ];
    }

    /* ---- relations ----------------------------------------------------- */

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ApplicationAnswer::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function stageEvents(): HasMany
    {
        // The id is the tiebreaker, and it is not decoration. Several moves can
        // land inside the same second — an invitation that moves the stage and
        // the submission that follows it, a reviewer working quickly — and
        // created_at alone then leaves the order to the database. A stage
        // history is the one list where that is not acceptable: it is read as
        // a sequence of decisions, and two of them appearing the wrong way
        // round misrepresents who did what after what.
        return $this->hasMany(ApplicationStageEvent::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function notes(): HasMany
    {
        // Same tiebreaker as stageEvents(), for the same reason: two notes
        // written in one minute read as a conversation, and an unstable
        // order makes a reply appear above what it replied to.
        return $this->hasMany(ApplicationNote::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssessmentAssignment::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(ReviewerScore::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ReviewerReview::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class)->orderBy('round');
    }

    /** Every offer ever drafted for this application, newest last. */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->orderBy('id');
    }

    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class)->latestOfMany();
    }

    public function onboardingItems(): HasMany
    {
        return $this->hasMany(OnboardingItem::class);
    }

    public function referenceContacts(): HasMany
    {
        return $this->hasMany(ReferenceContact::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RecruitmentMessage::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /* ---- scopes -------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [
            ApplicationStage::Hired->value,
            ApplicationStage::Rejected->value,
            ApplicationStage::Withdrawn->value,
        ]);
    }

    public function scopeAtStage(Builder $query, ApplicationStage $stage): Builder
    {
        return $query->where('stage', $stage->value);
    }

    /**
     * Applications a given reviewer is allowed to see.
     *
     * A plain reviewer sees the vacancies they were assigned to and nothing
     * else. This is applied to the query, not to the rendered rows, so there
     * is no route by which a reviewer can page past the boundary.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (\App\Support\RecruitmentPermission::allows($user, \App\Support\RecruitmentPermission::REVIEW_ALL)) {
            return $query;
        }

        return $query->whereHas(
            'vacancy.reviewers',
            fn (Builder $reviewers) => $reviewers->whereKey($user->id),
        );
    }

    /* ---- state --------------------------------------------------------- */

    public function isActive(): bool
    {
        return $this->stage instanceof ApplicationStage && $this->stage->isActive();
    }

    public function isWithdrawn(): bool
    {
        return $this->stage === ApplicationStage::Withdrawn;
    }

    public function isRejected(): bool
    {
        return $this->stage === ApplicationStage::Rejected;
    }

    /** The assignment a candidate is working on, or the latest one. */
    public function currentAssignment(): ?AssessmentAssignment
    {
        return $this->assignments()
            ->whereNull('cancelled_at')
            ->orderByDesc('id')
            ->first();
    }

    /** The CV as submitted. Later versions never replace it. */
    public function cv(): ?ApplicationDocument
    {
        return $this->documents()
            ->where('kind', ApplicationDocument::KIND_CV)
            ->orderByDesc('version')
            ->first();
    }

    /** The interview that is still to happen, if there is one. */
    public function upcomingInterview(): ?Interview
    {
        return $this->interviews()
            ->whereIn('status', [Interview::SCHEDULED, Interview::RESCHEDULED])
            ->where('scheduled_at', '>=', now()->subHours(2))
            ->orderBy('scheduled_at')
            ->first();
    }

    /* ---- display -------------------------------------------------------- */

    public function sourceLabel(): string
    {
        return SourceAttribution::label($this->source_channel);
    }

    /** What the candidate is told their stage is. Never a reason or a score. */
    public function candidateStageLabel(): string
    {
        return $this->stage instanceof ApplicationStage
            ? $this->stage->candidateLabel()
            : 'Application received';
    }

    /**
     * The expected-pay answer, written out.
     *
     * Minor units in, a sentence out. Nothing here converts between
     * currencies: a candidate who asked for dollars asked for dollars.
     */
    public function expectedSalaryLine(): ?string
    {
        if (blank($this->expected_salary_min_minor)) {
            return null;
        }

        $currency = strtoupper((string) ($this->expected_salary_currency ?: 'NGN'));
        $symbol   = match ($currency) {
            'NGN'   => '₦',
            'USD'   => '$',
            'GBP'   => '£',
            default => $currency . ' ',
        };

        $low  = $symbol . number_format(((int) $this->expected_salary_min_minor) / 100);
        $high = $this->expected_salary_max_minor
            ? $symbol . number_format(((int) $this->expected_salary_max_minor) / 100)
            : null;

        $period = Vacancy::PAY_PERIODS[$this->expected_pay_period] ?? '';

        return trim(($high && $high !== $low ? $low . ' - ' . $high : $low) . ' ' . $period);
    }

    /**
     * Has every assigned reviewer finished scoring?
     *
     * Used to show an incomplete review as incomplete. A missing score is a
     * visible gap, never an assumed zero — and nothing in this module reads
     * these numbers and moves a stage by itself.
     */
    public function scoringComplete(): bool
    {
        $reviewerIds = $this->vacancy?->reviewers()->pluck('users.id') ?? collect();

        if ($reviewerIds->isEmpty()) {
            return false;
        }

        $submitted = $this->reviews()
            ->whereIn('user_id', $reviewerIds)
            ->whereNotNull('submitted_at')
            ->count();

        return $submitted >= $reviewerIds->count();
    }

    /** The average score, or null when nobody has scored anything yet. */
    public function averageScore(): ?float
    {
        $scores = $this->scores()->whereNotNull('score')->pluck('score');

        return $scores->isEmpty() ? null : round($scores->avg(), 2);
    }

    /* ---- the person, borrowed for display ------------------------------ */

    /*
     * A candidate's name, email, phone and location live on the candidates
     * row, once, so that correcting a misspelled name or honouring a deletion
     * request touches one record rather than every application that person
     * ever sent. These six accessors exist only so a screen or an export can
     * say `$application->full_name` without reaching through the relation
     * every time.
     *
     * They are read-only and they are not columns, so nothing sorts or filters
     * SQL by them — admin tables go through `candidate.full_name`, which is a
     * real join.
     */

    public function getFullNameAttribute(): ?string
    {
        return $this->candidate?->full_name;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->candidate?->email;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->candidate?->phone;
    }

    public function getCountryAttribute(): ?string
    {
        return $this->candidate?->country;
    }

    public function getCityAttribute(): ?string
    {
        return $this->candidate?->city;
    }

    public function getTimezoneAttribute(): ?string
    {
        return $this->candidate?->timezone;
    }
}
