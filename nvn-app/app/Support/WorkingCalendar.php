<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The office's working week, for deadlines and delayed sends.
 *
 * Every timestamp this application stores is UTC. This class exists for the
 * one question UTC cannot answer: when is "three working days from now", given
 * that the office is in Lagos and does not work at weekends?
 *
 * Two rules worth stating because they are easy to get subtly wrong:
 *
 *   - Counting starts from the day AFTER the reference instant. An invitation
 *     dispatched at 4pm on Friday with three working days to run is due on
 *     Wednesday, not Tuesday — Friday is not one of the candidate's three
 *     days, because almost none of it was left.
 *   - The deadline is rendered at a fixed hour in the business timezone and
 *     then converted back to UTC for storage. A deadline of "17:00" must mean
 *     5pm in Lagos for every candidate, including one in Toronto, or two
 *     people invited together get different amounts of time.
 */
class WorkingCalendar
{
    /** The business clock: Africa/Lagos unless configured otherwise. */
    public static function timezone(): string
    {
        return (string) config('recruitment.timezone', 'Africa/Lagos');
    }

    /** @return list<int> Carbon ISO day numbers, 1 = Monday */
    public static function workingDays(): array
    {
        $days = config('recruitment.working_days', [1, 2, 3, 4, 5]);

        return array_values(array_map('intval', (array) $days));
    }

    /** @return list<string> Y-m-d dates the office is shut */
    public static function holidays(): array
    {
        return array_values(array_filter(array_map(
            fn ($date) => is_string($date) ? trim($date) : null,
            (array) config('recruitment.holidays', []),
        )));
    }

    /** Is this date one the office works? */
    public static function isWorkingDay(CarbonInterface $date): bool
    {
        $local = CarbonImmutable::parse($date)->setTimezone(static::timezone());

        if (! in_array($local->dayOfWeekIso, static::workingDays(), true)) {
            return false;
        }

        return ! in_array($local->format('Y-m-d'), static::holidays(), true);
    }

    /**
     * Add whole working days, then land on a given hour in Lagos.
     *
     * Zero days means "today, at that hour" — which may already be in the
     * past, and callers that must not produce a past deadline say so by
     * passing at least one day.
     */
    public static function addWorkingDays(
        CarbonInterface $from,
        int $days,
        ?string $atTime = null,
    ): CarbonImmutable {
        $cursor    = CarbonImmutable::parse($from)->setTimezone(static::timezone());
        $remaining = max(0, $days);

        while ($remaining > 0) {
            $cursor = $cursor->addDay();

            if (static::isWorkingDay($cursor)) {
                $remaining--;
            }
        }

        // Zero days still has to land on a working day: a deadline cannot fall
        // on a Sunday just because the invitation went out on a Saturday.
        while (! static::isWorkingDay($cursor)) {
            $cursor = $cursor->addDay();
        }

        if ($atTime !== null) {
            [$hour, $minute] = array_pad(array_map('intval', explode(':', $atTime)), 2, 0);
            $cursor = $cursor->setTime($hour, $minute);
        }

        // Back to UTC: that is what the column holds.
        return $cursor->utc();
    }

    /**
     * Subtract whole working days — used to place a reminder before a
     * deadline. The hour of the deadline is preserved unless one is given.
     */
    public static function subWorkingDays(
        CarbonInterface $from,
        int $days,
        ?string $atTime = null,
    ): CarbonImmutable {
        $cursor    = CarbonImmutable::parse($from)->setTimezone(static::timezone());
        $remaining = max(0, $days);

        while ($remaining > 0) {
            $cursor = $cursor->subDay();

            if (static::isWorkingDay($cursor)) {
                $remaining--;
            }
        }

        while (! static::isWorkingDay($cursor)) {
            $cursor = $cursor->subDay();
        }

        if ($atTime !== null) {
            [$hour, $minute] = array_pad(array_map('intval', explode(':', $atTime)), 2, 0);
            $cursor = $cursor->setTime($hour, $minute);
        }

        return $cursor->utc();
    }

    /** How many working days lie between two instants, ignoring the time. */
    public static function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = CarbonImmutable::parse($from)->setTimezone(static::timezone())->startOfDay();
        $end   = CarbonImmutable::parse($to)->setTimezone(static::timezone())->startOfDay();

        if ($end <= $start) {
            return 0;
        }

        $count  = 0;
        $cursor = $start;

        while ($cursor < $end) {
            $cursor = $cursor->addDay();

            if (static::isWorkingDay($cursor)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * How a deadline is written for a candidate.
     *
     * Always with the zone named. "Due Wednesday at 5pm" is ambiguous to
     * somebody in London and actively misleading to somebody in Vancouver.
     */
    public static function forHumans(CarbonInterface $at, ?string $timezone = null): string
    {
        $zone = $timezone ?: static::timezone();

        return CarbonImmutable::parse($at)
            ->setTimezone($zone)
            ->format('l j F Y, g:ia') . ' (' . static::zoneLabel($zone) . ')';
    }

    /**
     * A short zone label, e.g. "WAT" where the platform knows one.
     *
     * The argument is optional for the same reason forHumans()'s is: a caller
     * that holds a person's own zone passes it, and a caller with nobody in
     * particular in mind means the platform's working clock. Requiring it
     * bought nothing but a 500 on the one page a shortlisted candidate has to
     * use.
     */
    public static function zoneLabel(?string $timezone = null): string
    {
        $zone = $timezone ?: static::timezone();

        return CarbonImmutable::now($zone)->format('T') ?: $zone;
    }

    /** The business-clock rendering of an instant, for an admin screen. */
    public static function inBusinessTime(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone(static::timezone());
    }
}
