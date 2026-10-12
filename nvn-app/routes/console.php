<?php

use Illuminate\Support\Facades\Schedule;

/*
| Naija Virtual Notary — console scheduling.
|
| One per-minute server cron invokes `schedule:run`, which fires both of these:
|   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
|
| If the host will not allow a per-minute cron, use its shortest interval. The
| default NVN_QUEUE_MAX_TIME already suits a five-minute floor — read the note
| in config/nvn.php before changing it, because the number is a ceiling on a
| busy worker rather than how long one stays alive.
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

/*
| Recruitment.
|
| All three are no-ops while NVN_RECRUITMENT_ENABLED is false, so they are safe
| to schedule long before anybody is meant to see a vacancy. Each checks the
| switch itself rather than being wrapped in a condition here, because a
| schedule that silently disappears is harder to debug than a command that says
| why it did nothing.
*/

// The outbox drain. Every minute, because a candidate's single-use sign-in
// link is useless if it arrives five minutes after they asked for it, and
// because the per-minute `queue:work` above is the pattern the host already
// tolerates. Bounded by --limit, and overlap-protected both here and by a
// row-level lock inside dispatchDue().
Schedule::command('nvn:recruitment-dispatch')
    ->everyMinute()
    ->withoutOverlapping(10);

// Overdue assessments and spent sign-in tokens. Early, before the office
// opens, so a reviewer's first look at the queue is already accurate.
Schedule::command('nvn:recruitment-sweep')
    ->dailyAt('07:30')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(30);

// The retention purge. It refuses to delete anything until an approved
// retention period is set, and says so instead, so this entry is inert rather
// than dangerous on a fresh install.
Schedule::command('nvn:recruitment-retention')
    ->dailyAt('02:00')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(60);
