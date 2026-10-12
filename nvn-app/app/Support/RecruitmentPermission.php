<?php

namespace App\Support;

use App\Models\JobApplication;
use App\Models\User;

/**
 * Who may do what in recruitment.
 *
 * This application has no policy classes — authorization is role middleware
 * plus explicit checks — so recruitment follows that house style with one
 * ability map rather than a dozen policy files.
 *
 * Why a separate column at all: `users.role` is a MySQL enum of client,
 * notary and admin. Widening it needs raw per-driver SQL that the SQLite test
 * suite would reject, and every role check on the platform would have to learn
 * about a fourth kind of account. So recruitment staff are ordinary users
 * carrying a `recruitment_role`, and the two systems stay independent:
 *
 *   - A platform admin gets everything, because they already can reach
 *     everything by other doors.
 *   - A recruitment reviewer can enter the admin panel, and every resource in
 *     it that is not recruitment refuses them (App\Filament\Concerns\AdminOnly).
 *     They see applications and nothing about money, payouts, payments or a
 *     client's notarized documents.
 *   - A client or notary with no recruitment_role has no recruitment access of
 *     any kind, which is the default for every account on the platform.
 */
class RecruitmentPermission
{
    /* ---- abilities ---------------------------------------------------- */

    public const MANAGE_VACANCIES   = 'vacancies.manage';
    public const REVIEW             = 'applications.review';
    public const REVIEW_ALL         = 'applications.review_all';
    public const MOVE_STAGE         = 'applications.move';
    public const SCHEDULE_INTERVIEW = 'interviews.schedule';
    public const APPROVE_OFFER      = 'offers.approve';
    public const VIEW_SENSITIVE     = 'sensitive.view';
    public const EXPORT             = 'data.export';
    public const MANAGE_RETENTION   = 'retention.manage';

    /* ---- recruitment roles -------------------------------------------- */

    /** Scores the applications they are assigned to, and nothing else. */
    public const REVIEWER = 'reviewer';

    /** Runs the pipeline day to day: moves stages, books interviews. */
    public const COORDINATOR = 'coordinator';

    /** The above, plus offers, identity documents and exports. */
    public const HR_ADMIN = 'hr_admin';

    /** Owns the function: vacancies and the retention policy as well. */
    public const LEAD = 'lead';

    public const ROLES = [
        self::REVIEWER    => 'Reviewer (assigned applications only)',
        self::COORDINATOR => 'Coordinator (pipeline and interviews)',
        self::HR_ADMIN    => 'HR administrator (offers and onboarding documents)',
        self::LEAD        => 'Recruitment lead (everything)',
    ];

    /**
     * The grant table.
     *
     * Note what HR_ADMIN does not have: MANAGE_VACANCIES. Publishing a vacancy
     * is a commitment to a salary and a headcount, and it is kept with the
     * lead on purpose.
     */
    private const GRANTS = [
        self::REVIEWER => [
            self::REVIEW,
        ],
        self::COORDINATOR => [
            self::REVIEW,
            self::REVIEW_ALL,
            self::MOVE_STAGE,
            self::SCHEDULE_INTERVIEW,
        ],
        self::HR_ADMIN => [
            self::REVIEW,
            self::REVIEW_ALL,
            self::MOVE_STAGE,
            self::SCHEDULE_INTERVIEW,
            self::APPROVE_OFFER,
            self::VIEW_SENSITIVE,
            self::EXPORT,
        ],
        self::LEAD => [
            self::MANAGE_VACANCIES,
            self::REVIEW,
            self::REVIEW_ALL,
            self::MOVE_STAGE,
            self::SCHEDULE_INTERVIEW,
            self::APPROVE_OFFER,
            self::VIEW_SENSITIVE,
            self::EXPORT,
            self::MANAGE_RETENTION,
        ],
    ];

    /** Does this user hold this ability at all? */
    public static function allows(?User $user, string $ability): bool
    {
        if (! $user || ! static::moduleEnabled()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $role = (string) ($user->recruitment_role ?? '');

        return in_array($ability, self::GRANTS[$role] ?? [], true);
    }

    /** Anything at all? Used to decide whether the panel group is shown. */
    public static function allowsAny(?User $user): bool
    {
        if (! $user || ! static::moduleEnabled()) {
            return false;
        }

        return $user->isAdmin() || isset(self::GRANTS[(string) ($user->recruitment_role ?? '')]);
    }

    /**
     * The signed-in member of staff, or nobody.
     *
     * Named guard, and not auth()->user(). Laravel's Authenticate middleware
     * calls shouldUse() with whichever guard let the request through, so
     * inside a candidate-area request the default guard is 'candidate' and the
     * bare call answers with a Candidate. The instanceof below already made
     * that safe; asking the users table directly makes it obvious.
     */
    public static function current(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }

    public static function check(string $ability): bool
    {
        return static::allows(static::current(), $ability);
    }

    /**
     * May this user see this particular application?
     *
     * A plain reviewer sees only what they were assigned, which is the whole
     * point of assigning reviewers. Everyone above them sees the pipeline.
     */
    public static function canReview(?User $user, JobApplication $application): bool
    {
        if (! static::allows($user, self::REVIEW)) {
            return false;
        }

        if (static::allows($user, self::REVIEW_ALL)) {
            return true;
        }

        return $application->vacancy?->reviewers()
            ->whereKey($user->id)
            ->exists() ?? false;
    }

    /** Is the recruitment module switched on for this installation? */
    public static function moduleEnabled(): bool
    {
        return Settings::bool('recruitment_enabled', (bool) config('recruitment.enabled', false));
    }
}
