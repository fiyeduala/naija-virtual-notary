<?php

namespace App\Providers;

use App\Services\Video\AgoraVideoProvider;
use App\Services\Video\DailyVideoProvider;
use App\Services\Video\ManualVideoProvider;
use App\Services\Video\VideoProvider;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
