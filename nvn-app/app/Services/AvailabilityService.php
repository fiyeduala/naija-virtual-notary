<?php

namespace App\Services;

use App\Models\NotaryProfile;
use App\Models\Session;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Computes bookable time slots for a notary from their recurring weekly
 * availability and one-off overrides, minus slots already taken by scheduled
 * sessions. Slot length comes from config('nvn.session_slot_minutes').
 */
class AvailabilityService
{
    public function __construct(private int $slotMinutes = 0)
    {
        $this->slotMinutes = $this->slotMinutes ?: (int) config('nvn.session_slot_minutes', 30);
    }

    /**
     * Return bookable slots for the next $days days.
     * Shape: [ 'YYYY-MM-DD' => [ ['start'=>ISO,'end'=>ISO,'label'=>'9:00 AM'], ... ], ... ]
     */
    public function slotsFor(NotaryProfile $notary, int $days = 14): array
    {
        $notary->loadMissing('availability', 'availabilityOverrides');

        $firstRule = $notary->availability->first();
        $tz = $firstRule?->timezone ?? 'Africa/Lagos';
        $now = Carbon::now($tz);
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->addDays($days)->endOfDay();

        // Pre-load taken slots in the window
        $taken = Session::whereHas('request', fn ($q) => $q->where('notary_id', $notary->id))
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereBetween('scheduled_start_at', [$start, $end])
            ->pluck('scheduled_start_at')
            ->map(fn ($d) => $d->copy()->setTimezone($tz)->format('Y-m-d H:i'))
            ->flip();

        $result = [];

        foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
            $windows = $this->windowsForDay($notary, $day, $tz);

            foreach ($windows as $window) {
                $cursor = $window['start']->copy();

                while ($cursor->copy()->addMinutes($this->slotMinutes)->lessThanOrEqualTo($window['end'])) {
                    $slotStart = $cursor->copy();
                    $slotEnd = $cursor->copy()->addMinutes($this->slotMinutes);

                    // Skip past slots and already-taken slots
                    $isFuture = $slotStart->greaterThan($now);
                    $isFree = ! $taken->has($slotStart->format('Y-m-d H:i'));

                    if ($isFuture && $isFree) {
                        $result[$day->format('Y-m-d')][] = [
                            'start' => $slotStart->toIso8601String(),
                            'end'   => $slotEnd->toIso8601String(),
                            'label' => $slotStart->format('g:i A'),
                        ];
                    }

                    $cursor->addMinutes($this->slotMinutes);
                }
            }
        }

        return $result;
    }

    /**
     * Determine the open time windows for a given day, applying overrides.
     * Returns array of ['start'=>Carbon,'end'=>Carbon].
     */
    private function windowsForDay(NotaryProfile $notary, Carbon $day, string $tz): array
    {
        // Override for this exact date takes precedence
        $override = $notary->availabilityOverrides
            ->firstWhere('date', $day->toDateString());

        if ($override) {
            if (! $override->available) {
                return []; // explicitly blocked
            }

            return [[
                'start' => $this->at($day, $override->start_time, $tz),
                'end'   => $this->at($day, $override->end_time, $tz),
            ]];
        }

        // Recurring weekly availability for this weekday
        $windows = $notary->availability
            ->where('day_of_week', (int) $day->dayOfWeek)
            ->map(fn ($a) => [
                'start' => $this->at($day, $a->start_time, $tz),
                'end'   => $this->at($day, $a->end_time, $tz),
            ])
            ->values()
            ->all();

        // When the notary has set no availability rules at all, default to
        // 9am–5pm Mon–Fri so the marketplace doesn't show zero slots.
        if ($windows === [] && $notary->availability->isEmpty() && $day->isWeekday()) {
            return [[
                'start' => $this->at($day, '09:00', $tz),
                'end'   => $this->at($day, '17:00', $tz),
            ]];
        }

        return $windows;
    }

    private function at(Carbon $day, $time, string $tz): Carbon
    {
        $t = Carbon::parse($time);

        return $day->copy()->setTimezone($tz)->setTime($t->hour, $t->minute, 0);
    }
}
