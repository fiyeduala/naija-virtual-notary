<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
| Naija Virtual Notary — application bootstrap.
|
| This is a COMPLETE bootstrap/app.php. It registers the two custom middleware
| aliases (Phase 2) and excludes the Paystack webhook from CSRF (Phase 3/5).
| Drop it in over the fresh-install version.
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Custom middleware aliases
        $middleware->alias([
            'role'         => \App\Http\Middleware\EnsureUserHasRole::class,
            'verified.otp' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // Paystack posts to this route from outside — exclude it from CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paystack',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
