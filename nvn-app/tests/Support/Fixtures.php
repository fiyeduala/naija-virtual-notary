<?php

namespace Tests\Support;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\NotaryAsset;
use App\Models\NotaryProfile;
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

    /** Somebody who buys a notarization. */
    protected function makeClient(array $overrides = []): User
    {
        return User::create(array_merge([
            'full_name' => 'Client ' . $this->nextSeq(),
            'email'     => 'client' . $this->fixtureSeq . '@example.test',
            'password'  => 'password',
            'role'      => 'client',
            'status'    => 'active',
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

        $user = User::create([
            'full_name' => 'Notary ' . $seq,
            'email'     => 'notary' . $seq . '@example.test',
            'password'  => 'password',
            'role'      => 'notary',
            'status'    => 'active',
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

    private function nextSeq(): int
    {
        return ++$this->fixtureSeq;
    }
}
