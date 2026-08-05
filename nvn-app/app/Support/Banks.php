<?php

namespace App\Support;

use App\Services\PaystackService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The Nigerian bank list, as [code => name].
 *
 * Paystack's /bank endpoint is the authority — new banks and the constant churn
 * of microfinance/fintech institutions mean a hard-coded list goes stale. But
 * the payout form must not become unusable when the API is unreachable (no
 * outbound network on a locked-down shared host, an outage, or local dev with
 * no keys), so a bundled list of the institutions that cover almost every
 * notary stands in until the live list can be fetched.
 *
 * Codes in FALLBACK are NUBAN codes and are stable; they are what Paystack
 * itself returns for these banks.
 */
class Banks
{
    private const CACHE_KEY = 'paystack.banks.ngn';
    private const CACHE_TTL = 60 * 60 * 24; // a day — the list barely moves

    /** Enough coverage to onboard without the API. Sorted by name. */
    private const FALLBACK = [
        '044' => 'Access Bank',
        '063' => 'Access Bank (Diamond)',
        '035' => 'ALAT by Wema',
        '401' => 'ASO Savings and Loans',
        '023' => 'Citibank Nigeria',
        '050' => 'Ecobank Nigeria',
        '562' => 'Ekondo Microfinance Bank',
        '070' => 'Fidelity Bank',
        '011' => 'First Bank of Nigeria',
        '214' => 'First City Monument Bank',
        '501' => 'FSDH Merchant Bank',
        '00103' => 'Globus Bank',
        '058' => 'Guaranty Trust Bank',
        '030' => 'Heritage Bank',
        '301' => 'Jaiz Bank',
        '082' => 'Keystone Bank',
        '50211' => 'Kuda Bank',
        '565' => 'Carbon',
        '526' => 'Parallex Bank',
        '076' => 'Polaris Bank',
        '101' => 'Providus Bank',
        '221' => 'Stanbic IBTC Bank',
        '068' => 'Standard Chartered Bank',
        '232' => 'Sterling Bank',
        '100' => 'SunTrust Bank',
        '302' => 'TAJBank',
        '102' => 'Titan Bank',
        '032' => 'Union Bank of Nigeria',
        '033' => 'United Bank For Africa',
        '215' => 'Unity Bank',
        '566' => 'VFD Microfinance Bank',
        '035A' => 'Wema Bank',
        '057' => 'Zenith Bank',
        '999992' => 'OPay Digital Services',
        '999991' => 'PalmPay',
        '50515' => 'Moniepoint MFB',
    ];

    /** [code => name], live where possible. Never empty. */
    public static function all(): array
    {
        $live = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                return app(PaystackService::class)->banks();
            } catch (\Throwable $e) {
                Log::warning('Paystack bank list unavailable, using the bundled list.', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });

        // A failed fetch caches [] for the day, which would strand the form on
        // the bundled list. Forget it so the next request tries again.
        if ($live === []) {
            Cache::forget(self::CACHE_KEY);

            return self::FALLBACK;
        }

        return $live;
    }

    public static function name(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::all()[$code] ?? self::FALLBACK[$code] ?? null;
    }

    public static function exists(?string $code): bool
    {
        return $code !== null && self::name($code) !== null;
    }

    /** True when the live list is in play rather than the bundled one. */
    public static function isLive(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }
}
