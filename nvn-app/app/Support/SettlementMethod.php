<?php

namespace App\Support;

/**
 * How a payment or payout was actually settled.
 *
 * A NULL settlement_method means Paystack handled it and there is a webhook
 * behind it. Everything listed here means a human moved the money and a human
 * is vouching for it, which is exactly the distinction an auditor cares about.
 */
class SettlementMethod
{
    public const OPTIONS = [
        'bank_transfer' => 'Bank transfer',
        'cash'          => 'Cash',
        'pos'           => 'POS / card terminal',
        'cheque'        => 'Cheque',
        'other'         => 'Other',
    ];

    public static function label(?string $method): string
    {
        return $method === null
            ? 'Paystack'
            : (self::OPTIONS[$method] ?? ucfirst(str_replace('_', ' ', $method)));
    }

    public static function exists(?string $method): bool
    {
        return $method !== null && array_key_exists($method, self::OPTIONS);
    }
}
