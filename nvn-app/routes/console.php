<?php

use Illuminate\Support\Facades\Schedule;

/*
| Naija Virtual Notary — console scheduling.
|
| One per-minute server cron invokes `schedule:run`, which fires both of these:
|   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
|
| If the host will not allow a per-minute cron, use its shortest interval and
| set NVN_QUEUE_MAX_TIME to just under that interval in seconds.
|
| Locally, run `php artisan schedule:work` in a second terminal instead.
*/

// The 30-minute fallback engine (Phase 5) — checks every minute.
Schedule::command('nvn:process-fallbacks')
    ->everyMinute()
    ->withoutOverlapping(10);

// Drain the database queue without a persistent worker (shared-hosting friendly).
Schedule::command('queue:work --stop-when-empty --max-time=' . config('nvn.queue_max_time'))
    ->everyMinute()
    ->withoutOverlapping(10);

// Yearly partner memberships — warn before they lapse, and after.
// Once a day, mid-morning in Lagos rather than the app's UTC, so the mail
// lands inside a working day instead of before dawn where it is read last.
Schedule::command('nvn:membership-reminders')
    ->dailyAt('09:00')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(30);

// Approved notaries who never uploaded their signature, stamp or seal. Same
// hour, an hour apart, so the two runs never share a mail window.
Schedule::command('nvn:asset-reminders')
    ->dailyAt('10:00')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(30);
