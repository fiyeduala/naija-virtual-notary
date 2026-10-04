<?php

namespace Tests\Support;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\NotaryAsset;
use App\Models\NotaryProfile;
use App\Models\NotaryService;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;

/**
 * The fake world each test runs against.
 *
 * Deliberately not Eloquent factories. Adding those would mean putting a
 * HasFactory trait on the application's models to serve the tests, and a test
 * suite that has to modify the code it is testing before it can start is a
 * worse trade than a helper of its own. Everything here writes through the
 * ordinary models, so a column that stops being mass-assignable breaks these
 * loudly rather than quietly writing nothing.
 *
 * Amounts are minor units throughout — kobo — because that is what the
 * database holds. A test written in naira would be testing a different
 * application than the one that runs.
 */
trait Fixtures
{
    private int $fixtureSeq = 0;

    /**
     * Somebody who buys a notarization.
     *
     * Verified, because EnsureEmailIsVerified sits on every authenticated page
     * and an unverified account cannot open one — so a fixture without this
     * produces a user who can only ever be redirected. It is set here rather
     * than per test because it bit the suite once already: config('nvn
     * .require_otp_verification') defaults to TRUE, and a developer whose .env
     * carries NVN_REQUIRE_OTP=false sees every page render locally and watches
     * the same tests 302 on CI, where there is no .env to turn it off.
     *
     * A test that wants to prove the gate itself passes
     * ['email_verified_at' => null] and says so.
     */
    protected function makeClient(array $overrides = []): User
    {
        return User::create(array_merge([
            'full_name'         => 'Client ' . $this->nextSeq(),
            'email'             => 'client' . $this->fixtureSeq . '@example.test',
            'password'          => 'password',
            'role'              => 'client',
            'status'            => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    /**
     * A notary, and the user account behind them.
     *
     * Returns the profile rather than the user because the profile is what
     * money, sealing and listing all key off; $profile->user reaches the rest.
     */
    protected function makeNotary(array $overrides = []): NotaryProfile
    {
        $seq = $this->nextSeq();

        // Verified for the same reason makeClient() is — the desk is behind
        // EnsureEmailIsVerified too.
        $user = User::create([
            'full_name'         => 'Notary ' . $seq,
            'email'             => 'notary' . $seq . '@example.test',
            'password'          => 'password',
            'role'              => 'notary',
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);

        return NotaryProfile::create(array_merge([
            'user_id'                => $user->id,
            'verification_status'    => 'approved',
            'commission_rate'        => 50,
            'public_listing_enabled' => true,
            'membership_expires_at'  => now()->addYear(),
        ], $overrides));
    }

    /**
     * A finished job with its fee cleared — the shape the payout ledger counts.
     *
     * Completed, because unpaidPayments() only looks at completed work, and
     * successful + NGN + request_fee, because scopePayable() is what decides
     * whether money is owed at all. A helper that quietly produced anything
     * else would make the payout tests pass for the wrong reason.
     */
    protected function makeCompletedJob(
        NotaryProfile $notary,
        int $amountMinor = 4500000,
        array $requestOverrides = [],
        array $paymentOverrides = [],
    ): Payment {
        $client = $this->makeClient();

        $request = NotarizationRequest::create(array_merge([
            'reference'    => 'NVN-TEST-' . $this->nextSeq(),
            'client_id'    => $client->id,
            'notary_id'    => $notary->id,
            'status'       => RequestStatus::Completed->value,
            'currency'     => 'NGN',
            'is_offsite'   => false,
            'completed_at' => now(),
        ], $requestOverrides));

        return Payment::create(array_merge([
            'request_id'         => $request->id,
            'user_id'            => $client->id,
            'type'               => 'request_fee',
            'amount'             => $amountMinor,
            'currency'           => 'NGN',
            'status'             => 'successful',
            'paystack_reference' => 'PS-TEST-' . $this->nextSeq(),
            'completed_at'       => now(),
        ], $paymentOverrides));
    }

    /** An unpaid request sitting at the point a client is about to pay for it. */
    protected function makeUnpaidJob(NotaryProfile $notary, int $amountMinor = 4500000): Payment
    {
        return $this->makeCompletedJob(
            $notary,
            $amountMinor,
            ['status' => RequestStatus::Submitted->value, 'completed_at' => null],
            ['status' => 'pending', 'completed_at' => null],
        );
    }

    /** Give a notary the three marks without which nothing may be sealed. */
    protected function giveSealingAssets(NotaryProfile $notary, array $types = NotaryProfile::SEALING_ASSETS): void
    {
        foreach ($types as $type) {
            NotaryAsset::create([
                'notary_profile_id' => $notary->id,
                'type'              => $type,
                'file_url'          => 'notaries/' . $notary->id . '/' . $type . '.png',
            ]);
        }
    }

    /**
     * The platform's own notary — the only one that may seal organization work.
     *
     * Separate from makeNotary() rather than an override of it, because
     * is_system_native is what excludes a profile from the marketplace, from
     * the payout run and from public listing all at once. A test that reached
     * for it as one more override would read as though it were one more
     * setting.
     */
    protected function makeSystemNotary(array $overrides = []): NotaryProfile
    {
        return $this->makeNotary(array_merge([
            'is_system_native'       => true,
            'public_listing_enabled' => false,
        ], $overrides));
    }

    /** A priced category on a notary's own list. */
    protected function makeService(
        NotaryProfile $notary,
        int $priceNgnMinor = 2500000,
        array $overrides = [],
    ): NotaryService {
        return NotaryService::create(array_merge([
            'notary_profile_id'           => $notary->id,
            'service_type'                => 'Category ' . $this->nextSeq(),
            'price_ngn'                   => $priceNgnMinor,
            // Not nullable in the schema, so a fixture that left it out
            // would fail on the insert rather than on the assertion.
            'price_usd'                   => (int) round($priceNgnMinor / 1500),
            'active'                      => true,
            'estimated_duration_minutes'  => 20,
        ], $overrides));
    }

    /**
     * A live partner body that earns a share.
     *
     * Active and `commission` by default because that is the arrangement with
     * money in it and therefore the one most tests are about. A price_only
     * body is one override away, and the tests that need one say so out loud.
     */
    protected function makeOrganization(array $overrides = []): Organization
    {
        $seq = $this->nextSeq();

        return Organization::create(array_merge([
            'name'              => 'Body ' . $seq,
            'slug'              => 'body-' . $seq,
            'code'              => 'BODY' . $seq,
            'status'            => 'active',
            'arrangement'       => Organization::COMMISSION,
            'commission_rate'   => 20,
            'default_price_ngn' => 25000000,
            'contact_name'      => 'Contact ' . $seq,
            'contact_email'     => 'contact' . $seq . '@body.test',
            'phone'             => '08000000' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
            'email'             => 'portal' . $seq . '@body.test',
            'password'          => 'password',
            'bank_name'         => 'Test Bank',
            'account_name'      => 'Body ' . $seq,
            'account_number'    => '0123456789',
        ], $overrides));
    }

    /**
     * A finished job that this body referred, with its fee cleared.
     *
     * Freezes the rate onto the request the way the live intake does, because
     * every earning figure in the application reads the frozen rate and not
     * the body's current one. A fixture that left it null would make the
     * commission tests pass by computing nothing.
     */
    protected function makeOrganizationJob(
        Organization $organization,
        NotaryProfile $notary,
        int $amountMinor = 25000000,
        array $requestOverrides = [],
        array $paymentOverrides = [],
    ): Payment {
        return $this->makeCompletedJob(
            $notary,
            $amountMinor,
            array_merge([
                'organization_id'              => $organization->id,
                'organization_commission_rate' => $organization->earnsCommission()
                    ? $organization->commission_rate
                    : 0,
                'organization_referred_at'     => now(),
                'unit_fee_minor'               => $organization->default_price_ngn,
            ], $requestOverrides),
            $paymentOverrides,
        );
    }
    private function nextSeq(): int
    {
        return ++$this->fixtureSeq;
    }
}
