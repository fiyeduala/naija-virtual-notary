<?php

namespace App\Filament\Widgets;

use App\Models\PushSubscription;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Says out loud when admin alerts cannot possibly arrive.
 *
 * Web push has no failure surface. A missing signing key, a browser that was
 * never asked, a subscription the push service has since discarded — all four
 * look identical from a desk: nothing happens, and nothing is written anywhere
 * an admin would look. `php artisan nvn:push-check` walks the same ground, but
 * an admin who works in cPanel never opens a terminal, and this is the whole
 * reason the feature went months notifying nobody.
 *
 * So this sits on the dashboard and disappears the moment there is nothing to
 * report. Its presence is the alarm.
 */
class PushHealth extends Widget
{
    protected static string $view = 'filament.widgets.push-health';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /** canView() and the view both ask; the answer cannot change mid-request. */
    private static ?array $memo = null;
    private static bool $memoised = false;

    /** Only render when something is actually wrong. */
    public static function canView(): bool
    {
        return (bool) static::diagnosis();
    }

    /**
     * @return array{title: string, body: string, fix: array<int, string>}|null
     */
    public static function diagnosis(): ?array
    {
        if (static::$memoised) {
            return static::$memo;
        }

        static::$memoised = true;

        return static::$memo = static::examine();
    }

    /**
     * @return array{title: string, body: string, fix: array<int, string>}|null
     */
    private static function examine(): ?array
    {
        $public  = trim((string) config('nvn.vapid_public_key', ''));
        $private = trim((string) config('nvn.vapid_private_key', ''));

        if ($public === '' || $private === '') {
            return [
                'title' => 'Alerts are off — this server has no push signing keys',
                'body'  => 'No push notification can be sent, and nobody can switch alerts on in the '
                         . 'first place: the bell in the top bar has nothing to subscribe with. Email '
                         . 'and in-app notifications are unaffected.',
                'fix'   => [
                    'php artisan nvn:vapid-keys',
                    'Copy both lines into .env on the server',
                    'php artisan config:cache',
                    'Then reload this page and tap Alerts in the top bar',
                ],
            ];
        }

        // A key pasted with a line break survives a length check and dies at
        // send time with a cryptography error nobody sees.
        try {
            VAPID::validate(['subject' => config('app.url'), 'publicKey' => $public, 'privateKey' => $private]);
        } catch (Throwable $e) {
            return [
                'title' => 'Alerts are off — the push signing keys are not valid',
                'body'  => 'The keys are set but the library cannot read them: ' . $e->getMessage()
                         . '. Usually a line break or stray quotes picked up when pasting into .env.',
                'fix'   => [
                    'php artisan nvn:vapid-keys',
                    'Replace both lines in .env — each must be one unbroken line, unquoted',
                    'php artisan config:cache',
                ],
            ];
        }

        if (! Schema::hasTable('push_subscriptions')) {
            return [
                'title' => 'Alerts are off — the push_subscriptions table is missing',
                'body'  => 'Devices have nowhere to be recorded, so switching alerts on silently does nothing.',
                'fix'   => ['php artisan migrate --force'],
            ];
        }

        $adminIds = User::query()->admins()->pluck('id');
        $devices  = PushSubscription::whereIn('user_id', $adminIds)->count();

        if ($devices === 0) {
            return [
                'title' => 'Alerts can be sent, but no admin device is subscribed',
                'body'  => 'Signing keys are in place and sending works — there is simply nowhere to send to. '
                         . 'Installing the app is not the same as subscribing: the bell has to be tapped and '
                         . 'the browser prompt accepted, once per device. On iPhone this only works from a '
                         . 'Home Screen install, never a Safari tab.',
                'fix'   => ['Tap Alerts in the top bar of this page and accept the prompt'],
            ];
        }

        return null;
    }
}
