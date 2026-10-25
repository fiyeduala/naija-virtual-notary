<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Services\Video\AgoraVideoProvider;
use App\Services\Video\DailyVideoProvider;
use App\Services\Video\ManualVideoProvider;
use App\Services\Video\VideoProvider;
use App\Validation\NvnValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pluggable video provider. 'manual' = preview mode until a real SDK is
        // configured via VIDEO_PROVIDER in .env. 'daily' is the live one.
        $this->app->bind(VideoProvider::class, function () {
            return match (config('video.provider')) {
                'daily' => new DailyVideoProvider(),
                'agora' => new AgoraVideoProvider(),
                default => new ManualVideoProvider(),
            };
        });
    }

    public function boot(): void
    {
        // Accounts brought over from the old WordPress site sign in with the
        // password they already had; see LegacyUserProvider. Selected by
        // config/auth.php → providers.users.driver.
        Auth::provider('legacy-eloquent', fn ($app, array $config) => new LegacyUserProvider(
            $app['hash'],
            $config['model'],
        ));

        // Every validator in the application is ours, so that the one rule
        // NvnValidator narrows — no carriage returns inside an email address —
        // cannot be forgotten on a form added later.
        Validator::resolver(
            fn ($translator, $data, $rules, $messages = [], $attributes = []) =>
                new NvnValidator($translator, $data, $rules, $messages, $attributes)
        );

        $this->definePublicFormLimits();
    }

    /**
     * How often one person may submit a public form, and how often one
     * address may.
     *
     * These were a single per-IP limit, which is what Laravel gives you for
     * free and what every one of these forms used. It is the wrong shape for
     * who actually visits us. Most of our traffic arrives over Nigerian
     * mobile networks, where a great many phones sit behind one carrier
     * address, and a law firm or a business centre is one address with a room
     * of people behind it. A flat five-an-hour on the address means the sixth
     * honest person in that room is refused for something a stranger did, and
     * they are refused with no idea why.
     *
     * So each form now carries two limits. The tight one is on the person,
     * identified by the address they typed, and it is the one a normal
     * visitor could ever meet -- by mistyping a password rule four times, say,
     * since a rejected submission is still a submission as far as the limiter
     * is concerned. The loose one is on the IP, and it is what actually stops
     * a flood: a script can vary the email freely and still only get so far.
     *
     * The keys carry the route name, so two different forms never share a
     * bucket. Someone who has just applied for a job has not used up their
     * ability to sign up.
     */
    private function definePublicFormLimits(): void
    {
        // Signing up. Each submission sends an OTP email, so the ceiling is
        // what protects our sending reputation.
        RateLimiter::for(
            'signup',
            fn (Request $request) => $this->publicFormLimit($request, 'email', 5, 20)
        );

        // Applying -- to a vacancy, to be a notary partner, to be an
        // organization. Each writes a row and takes a file, and nobody has a
        // reason to send three in an hour, let alone from one address.
        RateLimiter::for(
            'application',
            fn (Request $request) => $this->publicFormLimit($request, 'email', 3, 12)
        );

        // The same form, but the address is under another name.
        RateLimiter::for(
            'organization-application',
            fn (Request $request) => $this->publicFormLimit($request, 'contact_email', 3, 12)
        );

        // Commenting on an article. A reader may be signed in or not.
        RateLimiter::for(
            'comment',
            fn (Request $request) => $this->publicFormLimit($request, 'author_email', 5, 20)
        );
    }

    /**
     * One limit on whoever typed that address, one on wherever they typed it.
     *
     * A submission with no address in it -- an empty form, or a script that
     * cannot be bothered -- falls back to the IP for both, which is exactly
     * the behaviour we had before and the right one for that case.
     *
     * @return array<int, Limit>
     */
    private function publicFormLimit(Request $request, string $field, int $perPerson, int $perAddress): array
    {
        $route  = $request->route()?->getName() ?? $request->path();
        $person = Str::lower(trim((string) $request->input($field)));

        return [
            Limit::perHour($perPerson)->by($route . ':who:' . ($person !== '' ? $person : $request->ip())),
            Limit::perHour($perAddress)->by($route . ':at:' . $request->ip()),
        ];
    }
}
