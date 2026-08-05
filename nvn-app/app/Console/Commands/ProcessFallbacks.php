<?php

namespace App\Console\Commands;

use App\Models\NotarizationRequest;
use App\Notifications\RequestOverdueNotification;
use App\Services\RequestFulfillmentService;
use Illuminate\Console\Command;

/**
 * The response-window watchdog.
 *
 * On shared hosting there is no always-on worker, so this runs from Laravel's
 * scheduler, which is invoked by a per-minute cron:
 *
 *     * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
 *
 * It used to reassign a paid request to the admin the moment the window
 * elapsed. It no longer does: the admin can take over any paid request at any
 * time from their desk, so a deadline that silently moves work around only made
 * the desk harder to reason about. What this does now is raise the request —
 * once — so nobody has to notice it by chance. The job stays with the assigned
 * notary until a human decides otherwise.
 */
class ProcessFallbacks extends Command
{
    protected $signature = 'nvn:process-fallbacks';
    protected $description = 'Flag paid requests the assigned notary has not answered within the response window';

    public function handle(RequestFulfillmentService $fulfillment): int
    {
        $overdue = NotarizationRequest::overdueForResponse()
            ->with('notary.user', 'client', 'session')
            ->get();

        if ($overdue->isEmpty()) {
            return self::SUCCESS;
        }

        $admin = $fulfillment->systemNativeUser();

        foreach ($overdue as $request) {
            try {
                // Stamp first: an alert that fails to send is better than one
                // that re-sends every minute for the life of the request.
                $request->update(['fallback_alerted_at' => now()]);

                $admin?->notify(new RequestOverdueNotification($request));

                $this->info("Flagged {$request->reference} as overdue.");
            } catch (\Throwable $e) {
                $this->error("Failed to flag {$request->reference}: {$e->getMessage()}");
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
