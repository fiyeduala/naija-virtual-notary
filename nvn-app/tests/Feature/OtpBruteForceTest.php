<?php

namespace Tests\Feature;

use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The six-digit code, and why five wrong guesses is where it stops.
 *
 * A million possibilities sounds like a lot until you divide it by a ten
 * minute window and a script. The route throttle caps how fast guesses can
 * arrive; these tests are about the other brake — the count of wrong codes one
 * account may offer — and about the two ways that brake could be useless:
 *
 *  - if asking for a fresh code reset the counter, the lockout would last
 *    exactly as long as it takes to click "resend";
 *  - if the code stayed valid after the lockout, somebody who guessed it on
 *    the sixth attempt could come back for it later.
 *
 * Both are checked below. The codes are set by hand rather than read out of a
 * mail fake, because what is being tested is the counting, not the delivery.
 */
class OtpBruteForceTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** An unverified account holding a code we know. */
    private function pending(string $code = '123456'): \App\Models\User
    {
        $user = $this->makeClient(['email_verified_at' => null]);

        $user->forceFill([
            'otp_code'       => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(OtpService::EXPIRY_MINUTES),
        ])->save();

        return $user;
    }

    public function test_five_wrong_codes_lock_the_account_out(): void
    {
        $user = $this->pending();
        $otp  = app(OtpService::class);

        for ($i = 1; $i <= OtpService::MAX_ATTEMPTS; $i++) {
            $this->assertFalse($otp->verify($user, '000000'), "guess {$i} should fail");
        }

        $this->assertTrue($otp->isLockedOut($user));

        // And the right code is no longer worth anything, because the lockout
        // threw it away rather than merely stopped reading it.
        $this->assertFalse($otp->verify($user, '123456'));
        $this->assertNull($user->fresh()->otp_code);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_correct_code_still_works_on_the_last_attempt(): void
    {
        $user = $this->pending();
        $otp  = app(OtpService::class);

        for ($i = 1; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $otp->verify($user, '000000');
        }

        $this->assertFalse($otp->isLockedOut($user), 'four wrong codes is not five');
        $this->assertTrue($otp->verify($user, '123456'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verifying_clears_the_counter(): void
    {
        $user = $this->pending();
        $otp  = app(OtpService::class);

        $otp->verify($user, '000000');
        $otp->verify($user, '000000');
        $this->assertSame(3, $otp->attemptsRemaining($user));

        $otp->verify($user, '123456');

        // A clean slate, so somebody who mistypes twice today is not two
        // guesses closer to a lockout the next time they verify an address.
        $this->assertSame(OtpService::MAX_ATTEMPTS, $otp->attemptsRemaining($user));
    }

    public function test_a_resend_does_not_hand_out_more_guesses(): void
    {
        $user = $this->pending();
        $otp  = app(OtpService::class);

        for ($i = 1; $i <= OtpService::MAX_ATTEMPTS; $i++) {
            $otp->verify($user, '000000');
        }

        // The whole point of keying the counter on the account rather than on
        // the code: a fresh code must not come with a fresh allowance.
        $this->assertFalse($otp->canResend($user));
        $this->assertSame(0, $otp->attemptsRemaining($user));

        $this->actingAs($user->fresh())
            ->post(route('verify.resend'))
            ->assertSessionHasErrors('code');

        // Still locked out, and still holding no code.
        $this->assertTrue($otp->isLockedOut($user));
        $this->assertNull($user->fresh()->otp_code);
    }

    public function test_the_lockout_is_per_account(): void
    {
        $locked = $this->pending();
        $other  = $this->pending('654321');
        $otp    = app(OtpService::class);

        for ($i = 1; $i <= OtpService::MAX_ATTEMPTS; $i++) {
            $otp->verify($locked, '000000');
        }

        $this->assertTrue($otp->isLockedOut($locked));

        // Nobody else is punished for it. The counter is keyed on the user
        // rather than the IP precisely so that one account cannot be used to
        // shut another out.
        $this->assertFalse($otp->isLockedOut($other));
        $this->assertTrue($otp->verify($other, '654321'));
    }

    public function test_the_screen_says_how_long_to_wait(): void
    {
        $user = $this->pending();
        $otp  = app(OtpService::class);

        for ($i = 1; $i <= OtpService::MAX_ATTEMPTS; $i++) {
            $otp->verify($user, '000000');
        }

        $response = $this->actingAs($user->fresh())
            ->from(route('verify.show'))
            ->post(route('verify.submit'), ['code' => '123456']);

        $response->assertSessionHasErrors('code');

        $this->assertStringContainsString(
            'Too many incorrect codes',
            session('errors')->first('code')
        );
    }

    public function test_the_verify_route_is_throttled(): void
    {
        $user = $this->pending();

        // throttle:10,10 on POST /verify. Eleven in a row is a 429 whatever
        // the service thinks, which is the brake that stops a script arriving
        // faster than the counter can be read.
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($user)->post(route('verify.submit'), ['code' => '000000']);
        }

        $this->actingAs($user)
            ->post(route('verify.submit'), ['code' => '000000'])
            ->assertStatus(429);
    }

    public function test_the_resend_route_is_throttled(): void
    {
        $user = $this->pending();

        RateLimiter::clear('otp-verify:' . $user->id);

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($user)->post(route('verify.resend'));
        }

        $this->actingAs($user)
            ->post(route('verify.resend'))
            ->assertStatus(429);
    }
}
