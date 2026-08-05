<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Small reporting helpers shared by the admin panel widgets and the notary
 * dashboard, so both read the same numbers off the same rules.
 *
 * Grouping is done in PHP rather than SQL on purpose. Date functions are the
 * least portable part of SQL — SQLite wants strftime(), MySQL DATE_FORMAT(),
 * Postgres to_char() — and this app is developed on SQLite but deployed to
 * cPanel MySQL. At this data volume the difference is unmeasurable, and it
 * means none of these screens break on the move.
 */
class Analytics
{
    /**
     * Count per calendar day across a trailing window, including empty days.
     *
     * @param  Collection<int, CarbonInterface|null>  $dates
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    public static function dailySeries(Collection $dates, int $days = 30): array
    {
        $start = now()->startOfDay()->subDays($days - 1);

        $counts = $dates
            ->filter()
            ->filter(fn (CarbonInterface $d) => $d->greaterThanOrEqualTo($start))
            ->countBy(fn (CarbonInterface $d) => $d->format('Y-m-d'));

        $labels = [];
        $values = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $labels[] = $day->format('j M');
            $values[] = (int) $counts->get($day->format('Y-m-d'), 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Count per day of the week, Monday first. Answers "which day do we take
     * the most orders on".
     *
     * @param  Collection<int, CarbonInterface|null>  $dates
     * @return array{labels: array<int, string>, values: array<int, int>, busiest: ?string}
     */
    public static function dayOfWeekSeries(Collection $dates): array
    {
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        // isoWeekday(): Monday = 1 … Sunday = 7, so it lines up with $labels.
        $counts = $dates->filter()->countBy(fn (CarbonInterface $d) => $d->isoWeekday());

        $values = [];
        foreach (range(1, 7) as $iso) {
            $values[] = (int) $counts->get($iso, 0);
        }

        $busiest = max($values) > 0
            ? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][array_search(max($values), $values, true)]
            : null;

        return ['labels' => $labels, 'values' => $values, 'busiest' => $busiest];
    }

    /**
     * Count per hour of the day, bucketed into the working blocks a notary
     * actually schedules in.
     *
     * @param  Collection<int, CarbonInterface|null>  $dates
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    public static function hourBlockSeries(Collection $dates): array
    {
        $blocks = [
            'Early (6–9)'    => [6, 9],
            'Morning (9–12)' => [9, 12],
            'Midday (12–3)'  => [12, 15],
            'Afternoon (3–6)'=> [15, 18],
            'Evening (6–9)'  => [18, 21],
            'Night (9–6)'    => [21, 30], // wraps past midnight
        ];

        $values = [];
        foreach ($blocks as [$from, $to]) {
            $values[] = $dates->filter()->filter(function (CarbonInterface $d) use ($from, $to) {
                $h = $d->hour;
                $h = ($from >= 21 && $h < 6) ? $h + 24 : $h;

                return $h >= $from && $h < $to;
            })->count();
        }

        return ['labels' => array_keys($blocks), 'values' => $values];
    }

    /** Minor units (kobo / cents) to a display string. */
    public static function money(int|float $minor, string $currency = 'NGN'): string
    {
        $major  = $minor / 100;
        $symbol = $currency === 'USD' ? '$' : '₦';

        // Prices here are whole naira, so "₦25,000" is what a headline number
        // should say. Decimals are only shown when there really are kobo.
        $decimals = fmod($major, 1) === 0.0 ? 0 : 2;

        return $symbol . number_format($major, $decimals);
    }

    /** Percentage, guarding the divide-by-zero that an empty dashboard hits. */
    public static function percent(int $part, int $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }

    /** Parse a column of timestamps off a query result into Carbon dates. */
    public static function dates(Collection $rows, string $column): Collection
    {
        return $rows->map(function ($row) use ($column) {
            $value = is_array($row) ? ($row[$column] ?? null) : ($row->{$column} ?? null);

            return $value ? Carbon::parse($value) : null;
        })->filter()->values();
    }
}
