<?php

use Illuminate\Support\Facades\Schedule;

/*
| Naija Virtual Notary — console scheduling.
|
| One per-minute server cron invokes `schedule:run`, which fires both of these:
|   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
|
| Locally, run `php artisan schedule:work` in a second terminal instead.
*/

// The 30-minute fallback engine (Phase 5) — checks every minute.
Schedule::command('nvn:process-fallbacks')
    ->everyMinute()
    ->withoutOverlapping();

// Drain the database queue without a persistent worker (shared-hosting friendly).
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();
