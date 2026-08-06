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
