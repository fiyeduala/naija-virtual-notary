<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Deployment check for web push.
 *
 * Push fails silently at every single step by design — a browser that refuses
 * permission says nothing to the server, a subscription that was never created
 * looks identical to one that was, a missing signing key makes the front-end
 * script return early, and a push service rejecting a send returns a status
 * code nobody reads. "I didn't get a notification" therefore has about six
 * possible causes and no error message anywhere.
 *
 * This walks them in order and says which one it is.
 *
 *   php artisan nvn:push-check
 *   php artisan nvn:push-check --send=you@example.com
 */
class PushCheck extends Command
{
    protected $signature = 'nvn:push-check
        {--send= : Email address of a user to send a real test push to}';

    protected $description = 'Diagnose why web push notifications are or are not arriving';

    private bool $blocked = false;

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>1. Signing keys</>');
        $keysOk = $this->checkKeys();

        $this->newLine();
        $this->line('<options=bold>2. Site address</>');
        $this->checkUrl();

        $this->newLine();
        $this->line('<options=bold>3. Who is subscribed</>');
        $subs = $this->checkSubscriptions();

        $this->newLine();
        $this->line('<options=bold>4. Delivery path</>');
        $this->checkDelivery();

        if ($email = $this->option('send')) {
            $this->newLine();
            $this->line('<options=bold>5. Test send</>');

            if (! $keysOk) {
                $this->error('  Skipped — there is nothing to sign the push with.');

                return self::FAILURE;
            }

            $this->testSend($email);
        } elseif ($subs > 0 && $keysOk) {
            $this->newLine();
            $this->comment('Everything above is in place. To prove it end to end:');
            $this->line('  php artisan nvn:push-check --send=your@email.address');
        }

        $this->newLine();

        return $this->blocked ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The keys are the usual answer. They live only in .env, they were never
     * part of any deploy, and an empty one makes the browser-side script return
     * before it ever asks for permission — so the bell appears to do nothing.
     */
    private function checkKeys(): bool
    {
        $public  = trim((string) config('nvn.vapid_public_key', ''));
        $private = trim((string) config('nvn.vapid_private_key', ''));

        if ($public === '' || $private === '') {
            $this->error('  MISSING — ' . ($public === '' ? 'VAPID_PUBLIC_KEY' : 'VAPID_PRIVATE_KEY')
                . ($public === '' && $private === '' ? ' and VAPID_PRIVATE_KEY are' : ' is') . ' empty.');
            $this->line('  Nothing can be sent, and nobody can subscribe in the first place —');
            $this->line('  the "Alerts" bell returns early with no error on screen.');
            $this->newLine();
            $this->line('  Fix:  php artisan nvn:vapid-keys');
            $this->line('        put both lines in .env');
            $this->line('        php artisan config:cache');
            $this->blocked = true;

            return false;
        }

        // Both are base64url of fixed-length binary: 65 bytes public, 32
        // private. A truncated paste is the other way this goes wrong, and it
        // fails later with an unhelpful cryptography error rather than here.
        $this->line('  Public key:  ' . substr($public, 0, 12) . '… (' . strlen($public) . ' chars)'
            . (strlen($public) === 87 ? ' <fg=green>ok</>' : ' <fg=red>expected 87 — truncated?</>'));
        $this->line('  Private key: set (' . strlen($private) . ' chars)'
            . (strlen($private) === 43 ? ' <fg=green>ok</>' : ' <fg=red>expected 43 — truncated?</>'));

        if (strlen($public) !== 87 || strlen($private) !== 43) {
            $this->blocked = true;

            return false;
        }

        // A key pasted with a line break or a stray quote survives the length
        // check and dies at send time, so make the library parse it now.
        try {
            \Minishlink\WebPush\VAPID::validate([
                'subject'    => config('app.url'),
                'publicKey'  => $public,
                'privateKey' => $private,
            ]);
            $this->info('  Both keys parse.');
        } catch (Throwable $e) {
            $this->error('  Keys do not parse: ' . $e->getMessage());
            $this->line('  Usually a line break or quotes picked up when pasting into .env.');
            $this->blocked = true;

            return false;
        }

        // Cached config is a snapshot. Editing .env without config:cache leaves
        // the old value live, which looks exactly like the edit not working.
        if (app()->configurationIsCached()) {
            $envPublic = trim((string) env('VAPID_PUBLIC_KEY', ''));
            if ($envPublic !== '' && $envPublic !== $public) {
                $this->warn('  Config is cached and does not match .env — run: php artisan config:cache');
            } else {
                $this->line('  <fg=gray>Config is cached; re-run config:cache after any .env edit.</>');
            }
        }

        return true;
    }

    /** Push needs HTTPS, and the VAPID subject has to be a real address. */
    private function checkUrl(): void
    {
        $url = (string) config('app.url');
        $this->line('  APP_URL: ' . ($url ?: '<fg=red>not set</>'));

        if (! str_starts_with($url, 'https://')) {
            $this->warn('  Not HTTPS. Browsers refuse to register a service worker except over');
            $this->warn('  HTTPS (localhost excepted), so no subscription can ever be created.');
        }
    }

    /**
     * Nobody being subscribed is the second most common answer, and it is
     * invisible from the browser: an install is not a subscription. Adding the
     * app to a Home Screen does not turn alerts on — the bell has to be tapped
     * and the permission prompt accepted, inside the installed app.
     */
    private function checkSubscriptions(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('push_subscriptions')) {
            $this->error('  The push_subscriptions table does not exist — run: php artisan migrate --force');
            $this->blocked = true;

            return 0;
        }

        $subs = PushSubscription::with('user')->latest('id')->get();

        if ($subs->isEmpty()) {
            $this->warn('  No subscriptions at all — nothing is registered to receive a push.');
            $this->newLine();
            $this->line('  Installing the app is not the same as subscribing. Open the site (or the');
            $this->line('  installed app), sign in, tap the <options=bold>Alerts</> bell in the top bar and accept');
            $this->line('  the browser prompt. Then run this again and you will appear below.');
            $this->newLine();
            $this->line('  On iPhone it only works from the Home Screen app, never a Safari tab.');
            $this->line('  If the bell was dismissed twice, Chrome blocks the site silently and it');
            $this->line('  has to be re-allowed from the padlock in the address bar.');

            return 0;
        }

        $this->info('  ' . $subs->count() . ' subscription' . ($subs->count() === 1 ? '' : 's') . ':');

        foreach ($subs as $sub) {
            $this->line(sprintf(
                '   • %-32s %-8s %-18s %s',
                $sub->user?->email ?? '(deleted user #' . $sub->user_id . ')',
                $sub->user?->role?->value ?? '?',
                $this->pushService($sub->endpoint),
                $sub->created_at?->diffForHumans() ?? '',
            ));
        }

        // Admin alerts are the ones people notice missing, and they fan out to
        // every admin — so "somebody is subscribed" is not the same answer as
        // "an admin is subscribed", and only the second one silences the desk.
        $adminIds = User::query()->admins()->pluck('id');
        $adminSubs = $subs->whereIn('user_id', $adminIds)->count();

        $this->newLine();
        if ($adminSubs === 0) {
            $this->warn('  No admin is subscribed — every admin alert has nowhere to go.');
            $this->line('  Sign in as an admin, tap the Alerts bell in the panel top bar, accept.');
        } else {
            $this->info('  ' . $adminSubs . ' admin device' . ($adminSubs === 1 ? '' : 's')
                . ' will receive admin alerts.');
        }

        // Every device is its own subscription. Someone who "has it on both"
        // and appears once has really only turned it on in one place.
        $perUser = $subs->groupBy('user_id');
        if ($perUser->count() < $subs->count()) {
            $this->line('  <fg=gray>Each device subscribes separately — phone and desktop are two rows.</>');
        }

        return $subs->count();
    }

    /** Which push service an endpoint belongs to, for reading the list. */
    private function pushService(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST) ?: 'unknown';

        return match (true) {
            str_contains($host, 'google')  => 'Chrome/Android',
            str_contains($host, 'mozilla') => 'Firefox',
            str_contains($host, 'apple')   => 'Safari/iOS',
            str_contains($host, 'windows') => 'Edge',
            default                        => $host,
        };
    }

    /**
     * Whether a send would actually leave the server. Only EmailOtpNotification
     * is queued, so a push is normally sent inside the web request and the
     * five-minute cron has nothing to do with it — worth stating, because that
     * cron is the first thing anyone blames.
     */
    private function checkDelivery(): void
    {
        $this->line('  Notifications are sent inline, not queued — a push leaves immediately');
        $this->line('  and does not wait for the cron. (Only the login OTP email is queued.)');

        $connection = config('queue.default');
        $this->line('  Queue connection: ' . $connection);

        if ($connection === 'database') {
            try {
                $pending = DB::table('jobs')->count();
                $failed  = DB::table('failed_jobs')->count();
                $oldest  = DB::table('jobs')->min('created_at');

                $this->line('  Pending jobs: ' . $pending . ($pending > 0 && $oldest
                    ? ' (oldest queued ' . now()->diffForHumans(now()->setTimestamp((int) $oldest), true) . ' ago)'
                    : ''));

                if ($failed > 0) {
                    $this->warn('  ' . $failed . ' failed job(s) — php artisan queue:failed');
                }
            } catch (Throwable $e) {
                $this->warn('  Could not read the queue tables: ' . $e->getMessage());
            }
        }

        $missing = collect(glob(app_path('Notifications/*.php')))
            ->merge(glob(app_path('Notifications/Admin/*.php')))
            ->reject(fn ($f) => str_contains(file_get_contents($f), 'toWebPush'))
            ->map(fn ($f) => basename($f, '.php'))
            ->values();

        if ($missing->isNotEmpty()) {
            $this->line('  <fg=gray>No push for: ' . $missing->implode(', ')
                . ' — these are email/in-app only.</>');
        }
    }

    /**
     * The real thing. Sends through the same library the app uses and prints
     * what each push service said, because that status code is the one piece of
     * information the normal path throws away.
     */
    private function testSend(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('  No user with that email address.');
            $this->blocked = true;

            return;
        }

        $subs = PushSubscription::where('user_id', $user->id)->get();

        if ($subs->isEmpty()) {
            $this->error('  ' . $user->email . ' has no subscription — nothing to send to.');
            $this->line('  Sign in as them, tap the Alerts bell, accept, then run this again.');
            $this->blocked = true;

            return;
        }

        $push = new WebPush([
            'VAPID' => [
                'subject'    => config('app.url'),
                'publicKey'  => config('nvn.vapid_public_key'),
                'privateKey' => config('nvn.vapid_private_key'),
            ],
        ]);

        $payload = json_encode([
            'title' => 'Naija Virtual Notary',
            'body'  => 'Test alert sent ' . now()->timezone('Africa/Lagos')->format('j M, g:i A')
                . '. If you can read this, push is working.',
            'icon'  => \App\Support\Branding::pushIconUrl(),
            'url'   => route('dashboard', absolute: false),
        ]);

        foreach ($subs as $sub) {
            $push->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                ]),
                $payload,
            );
        }

        $this->line('  Sending to ' . $subs->count() . ' device(s) for ' . $user->email . '…');
        $this->newLine();

        $delivered = 0;

        foreach ($push->flush() as $report) {
            $service = $this->pushService($report->getEndpoint());

            if ($report->isSuccess()) {
                $delivered++;
                $this->info('   ✓ ' . $service . ' — accepted');

                continue;
            }

            $this->error('   ✗ ' . $service . ' — ' . trim($report->getReason()));
            $this->line('     ' . $this->explain($report->getResponse()?->getStatusCode()));

            // A gone subscription is normal housekeeping: the browser was
            // reinstalled, or permission revoked. Clearing it is why the
            // person then sees nothing at all until they subscribe again.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                $this->line('     Removed — that device must turn alerts on again.');
            }
        }

        $this->newLine();

        if ($delivered === 0) {
            $this->error('  Nothing was accepted. Nobody will have seen anything.');
            $this->blocked = true;

            return;
        }

        $this->info('  ' . $delivered . ' of ' . $subs->count() . ' accepted by the push service.');
        $this->line('  Accepted means handed over successfully. A phone that is asleep gets it');
        $this->line('  on waking; a device with Focus or Do Not Disturb on may hold it silently.');
    }

    /** Plain English for the status codes these services return. */
    private function explain(?int $status): string
    {
        return match ($status) {
            400     => 'Malformed request — usually a corrupted stored subscription.',
            401,
            403     => 'The push service rejected the signature. The keys in .env are not the '
                     . 'ones this subscription was created with — regenerating keys invalidates '
                     . 'every existing subscription, so everyone must re-subscribe.',
            404,
            410     => 'The subscription no longer exists — app removed, browser data cleared, '
                     . 'or permission revoked.',
            413     => 'Payload too large.',
            429     => 'Rate limited — too many sends in a short window.',
            null    => 'No response from the push service — check outbound HTTPS from this server.',
            default => 'HTTP ' . $status . '.',
        };
    }
}
