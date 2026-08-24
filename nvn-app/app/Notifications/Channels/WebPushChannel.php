<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends a notification to whatever browsers a user has subscribed.
 *
 * Everything about push fails quietly — a missing signing key, a subscription
 * nobody ever created, a push service returning 403 into a void. This channel
 * used to swallow all of it, so "I never got the notification" arrived with no
 * matching line anywhere in the logs and nothing to look at.
 *
 * It still never throws (a failed push must not break the request that caused
 * it), but every reason it gives up is now written to the log under the
 * `webpush` context, and `php artisan nvn:push-check` walks the same ground
 * interactively.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush')) {
            return;
        }

        $type = class_basename($notification);

        // An empty key is the usual answer, and the one that looks least like a
        // fault: the front-end bell also returns early, so nobody ever
        // subscribed and there is nothing on screen to suggest why.
        if (! $this->keysPresent()) {
            Log::warning('webpush: not sent — VAPID keys are not set on this server', [
                'notification' => $type,
                'user_id'      => $notifiable->getKey(),
                'fix'          => 'php artisan nvn:vapid-keys, put both lines in .env, php artisan config:cache',
            ]);

            return;
        }

        $subscriptions = PushSubscription::where('user_id', $notifiable->getKey())->get();

        if ($subscriptions->isEmpty()) {
            Log::info('webpush: not sent — this user has no subscribed device', [
                'notification' => $type,
                'user_id'      => $notifiable->getKey(),
            ]);

            return;
        }

        $payload = json_encode($notification->toWebPush($notifiable));

        try {
            $push = new WebPush([
                'VAPID' => [
                    'subject'    => config('app.url'),
                    'publicKey'  => config('nvn.vapid_public_key'),
                    'privateKey' => config('nvn.vapid_private_key'),
                ],
            ]);

            foreach ($subscriptions as $sub) {
                $push->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                    ]),
                    $payload
                );
            }

            $reports = $push->flush();
        } catch (Throwable $e) {
            // Malformed keys die in the library rather than at the push service.
            // A notification failing must not take the web request with it.
            Log::error('webpush: send failed before anything left the server', [
                'notification' => $type,
                'user_id'      => $notifiable->getKey(),
                'error'        => $e->getMessage(),
            ]);

            return;
        }

        foreach ($reports as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            $status = $report->getResponse()?->getStatusCode();

            Log::warning('webpush: rejected by the push service', [
                'notification' => $type,
                'user_id'      => $notifiable->getKey(),
                'service'      => parse_url($report->getEndpoint(), PHP_URL_HOST),
                'status'       => $status,
                'reason'       => trim((string) $report->getReason()),
                'meaning'      => $this->explain($status),
            ]);

            // 404/410 means the browser threw the subscription away — cleared
            // data, uninstalled, permission revoked. Dropping the row is why
            // that person then hears nothing until they tap the bell again.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }

    private function keysPresent(): bool
    {
        return trim((string) config('nvn.vapid_public_key', '')) !== ''
            && trim((string) config('nvn.vapid_private_key', '')) !== '';
    }

    /** Plain English for the status codes these services return. */
    private function explain(?int $status): string
    {
        return match ($status) {
            400     => 'Malformed request — usually a corrupted stored subscription.',
            401,
            403     => 'Signature rejected. The VAPID keys in .env are not the ones this '
                     . 'subscription was created with — everyone must re-subscribe.',
            404,
            410     => 'The subscription no longer exists on that device.',
            413     => 'Payload too large.',
            429     => 'Rate limited.',
            null    => 'No response — check outbound HTTPS from this server.',
            default => 'HTTP ' . $status . '.',
        };
    }
}
