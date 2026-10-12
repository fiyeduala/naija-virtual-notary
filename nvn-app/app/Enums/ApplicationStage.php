<?php

namespace App\Enums;

/**
 * Where an application has got to.
 *
 * The stage is the single source of truth for what may happen next, and every
 * change goes through App\Services\Recruitment\ApplicationStageService — never
 * a bare `update(['stage' => ...])`, because a transition without a recorded
 * actor and reason is exactly the evidence a hiring decision needs to have.
 *
 * Two things are deliberately NOT stages:
 *
 *   assessment overdue   — a deadline passing is a fact about the assessment,
 *                          not a decision about the person. Keeping it as a
 *                          stage would mean a candidate who submits an hour
 *                          late has to be "moved back", losing the fact that
 *                          they submitted at all.
 *   offer accepted       — accepting an offer is not being hired. Checks may
 *                          still be outstanding, and conflating the two is how
 *                          somebody ends up marked Hired before a reference
 *                          was ever looked at.
 *
 * Both live as substates on the assessment and offer records respectively.
 */
enum ApplicationStage: string
{
    case Applied            = 'applied';
    case InitialReview      = 'initial_review';
    case AssessmentInvited  = 'assessment_invited';
    case AssessmentSubmitted = 'assessment_submitted';
    case Interview          = 'interview';
    case OfferChecks        = 'offer_checks';
    case Hired              = 'hired';
    case Rejected           = 'rejected';
    case Withdrawn          = 'withdrawn';

    /** What the office calls it. */
    public function label(): string
    {
        return match ($this) {
            self::Applied             => 'Applied',
            self::InitialReview       => 'Initial review',
            self::AssessmentInvited   => 'Assessment invited',
            self::AssessmentSubmitted => 'Assessment submitted',
            self::Interview           => 'Interview',
            self::OfferChecks         => 'Offer & checks',
            self::Hired               => 'Hired',
            self::Rejected            => 'Not progressing',
            self::Withdrawn           => 'Withdrawn',
        };
    }

    /**
     * What the CANDIDATE is told, which is not always what the office sees.
     *
     * "Initial review" is the same words either way; "Not progressing" is
     * phrased as a decision rather than a verdict on the person; and no
     * candidate-facing string ever reveals a reviewer, a score or a reason.
     */
    public function candidateLabel(): string
    {
        return match ($this) {
            self::Applied             => 'Application received',
            self::InitialReview       => 'Under review',
            self::AssessmentInvited   => 'Assessment to complete',
            self::AssessmentSubmitted => 'Assessment received',
            self::Interview           => 'Interview stage',
            self::OfferChecks         => 'Offer and checks',
            self::Hired               => 'Offer completed',
            self::Rejected            => 'Not progressing',
            self::Withdrawn           => 'Withdrawn',
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Applied, self::InitialReview       => 'gray',
            self::AssessmentInvited                  => 'warning',
            self::AssessmentSubmitted, self::Interview => 'info',
            self::OfferChecks                        => 'primary',
            self::Hired                              => 'success',
            self::Rejected, self::Withdrawn          => 'danger',
        };
    }

    /** Nothing moves out of these. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Hired, self::Rejected, self::Withdrawn], true);
    }

    /** Is this application still a live candidacy? */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The ordinary forward path, in order.
     *
     * Rejected and Withdrawn are off this list on purpose: they are reachable
     * from anywhere live, so they are not "next" from anything.
     */
    public static function pipeline(): array
    {
        return [
            self::Applied,
            self::InitialReview,
            self::AssessmentInvited,
            self::AssessmentSubmitted,
            self::Interview,
            self::OfferChecks,
            self::Hired,
        ];
    }

    /** Position on the pipeline, or null for a terminal side-exit. */
    public function position(): ?int
    {
        $index = array_search($this, self::pipeline(), true);

        return $index === false ? null : $index;
    }

    /**
     * The stage that ordinarily follows, with no skipping.
     *
     * A skip is still possible — some candidates are interviewed without an
     * assessment — but it has to be asked for explicitly and carries a reason.
     */
    public function next(): ?self
    {
        $position = $this->position();

        return $position === null ? null : (self::pipeline()[$position + 1] ?? null);
    }

    /** @return array<string, string> value => label, for a form */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $stage) => $carry + [$stage->value => $stage->label()],
            [],
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
