<?php

use App\Support\CloudflareProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        // The live subdomain sits behind Cloudflare, so the address on the
        // socket is an edge node and the visitor's own address is in
        // X-Forwarded-For. Trust that header from Cloudflare and nowhere else
        // — see App\Support\CloudflareProxies for why the list is not '*'.
        $middleware->trustProxies(
            at: CloudflareProxies::ranges(),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Custom middleware aliases
        $middleware->alias([
            'role'         => \App\Http\Middleware\EnsureUserHasRole::class,
            'verified.otp' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'candidate.session' => \App\Http\Middleware\EnsureCandidateSessionIsFresh::class,
        ]);

        // Which sign-in page an unauthenticated visitor is sent to.
        //
        // Laravel has one default, and with the organization portal there are
        // now two doors. A partner body whose session has expired was being
        // sent to the client login page, where its credentials do not exist
        // and nothing on the screen explains why — so the guest redirect is
        // chosen by which part of the site was asked for.
        // Three doors now: a job applicant signs in with an emailed link and
        // has no password, so sending them to the client login page would be
        // a dead end.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->routeIs('organization.*')) {
                return route('organization.login');
            }

            if ($request->routeIs('candidate.*')) {
                return route('candidate.login');
            }

            return route('login');
        });

        // Paystack posts to this route from outside — exclude it from CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paystack',
            // Posted by the browser itself out of its own reporting queue.
            // It holds no session and cannot carry a token. See
            // CspReportController.
            'csp-report',
        ]);

        // The response headers every page gets. Appended, so they are set on
        // the way out once everything else has had its say. The admin panel
        // assembles its own stack and lists this separately — see
        // AdminPanelProvider.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A 429 is an HttpException, and Laravel reports none of those. That
        // is the right default almost everywhere and the wrong one here: a
        // limit set too tight turns real people away, and it does it in
        // silence, so the only way we ever learn of it is somebody telling
        // us -- which is how we learned of it. Logging the refusal turns a
        // report that the site sometimes says too many requests into a line
        // naming the form, so a limit can be judged on what happened rather
        // than on a guess about which form was meant.
        //
        // Hooked onto rendering rather than reporting, which looks like the
        // obvious home for it and does not work: stopIgnoring() matches the
        // ignore list by exact class name, and what is on that list is
        // HttpException, not ThrottleRequestsException. Naming the subclass
        // removes nothing and the callback never runs. Returning null here
        // means we have not handled it, so Laravel goes on to render the
        // page in resources/views/errors/429.blade.php as usual.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            // The route and the address, and nothing the visitor typed. We
            // want to know which form is too tight and roughly who it hit,
            // not to keep a copy of their email in a log file.
            Log::warning('Throttled', [
                'route'       => $request->route()?->getName() ?? $request->path(),
                'method'      => $request->method(),
                'ip'          => $request->ip(),
                'user_id'     => $request->user()?->id,
                'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
            ]);

            return null;
        });
    })->create();
