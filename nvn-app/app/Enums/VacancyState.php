<?php

namespace App\Enums;

/**
 * The life of a vacancy.
 *
 * Five states rather than a boolean, because "not accepting applications"
 * covers three genuinely different situations the office needs to tell apart:
 *
 *   paused   — still hiring, temporarily not taking more. The page says so.
 *   closed   — the window has shut. The page stays readable, because a link
 *              that was shared on a job board should explain itself rather
 *              than 404 weeks later.
 *   archived — gone from the public site entirely.
 *
 * Only Published accepts a submission, and that is enforced on the server in
 * App\Models\Vacancy::acceptsApplications(), not by hiding the button.
 */
enum VacancyState: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Paused    = 'paused';
    case Closed    = 'closed';
    case Archived  = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Published => 'Published',
            self::Paused    => 'Paused',
            self::Closed    => 'Closed',
            self::Archived  => 'Archived',
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Draft     => 'gray',
            self::Published => 'success',
            self::Paused    => 'warning',
            self::Closed    => 'danger',
            self::Archived  => 'gray',
        };
    }

    /** Can a visitor who is not signed in reach this page at all? */
    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Published, self::Paused, self::Closed], true);
    }

    /** Does it appear in the /careers list of current openings? */
    public function isListed(): bool
    {
        return $this === self::Published;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $state) => $carry + [$state->value => $state->label()],
            [],
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
