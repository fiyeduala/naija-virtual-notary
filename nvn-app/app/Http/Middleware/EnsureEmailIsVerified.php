<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access until the account's email is verified via OTP.
 * Applied to the authenticated app routes (dashboards, requests, etc.).
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('nvn.require_otp_verification', true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verify.show');
        }

        return $next($request);
    }
}
