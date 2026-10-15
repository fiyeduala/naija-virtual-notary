<?php

namespace App\Validation;

use Illuminate\Validation\Validator;

/**
 * The application's validator, which exists for one rule.
 *
 * Laravel's `email` rule is the subject of GHSA-5vg9-5847-vvmq
 * (CVE-2026-48019): its default RFC check accepts an address whose local part
 * is a quoted string containing a carriage return or a line feed, because that
 * is technically legal in RFC 5322. Fed into a mail header afterwards, those
 * two characters end the header and begin another — so "Bcc:" and a stranger's
 * address can be smuggled through any form that takes an email address.
 *
 * Upstream fixed it in Laravel 12.60 and never backported it to the 11 branch,
 * which is where this application is. Rather than carry the hole until the
 * framework upgrade, the rule is narrowed here: no address may contain CR or
 * LF, whatever RFC 5322 permits. Nobody has one.
 *
 * It is done by subclassing rather than by Validator::extend() because an
 * extension only ever reaches __call(), and `email` is a real method on the
 * validator — so extending it registers a callback that is never consulted.
 * Registered in AppServiceProvider::boot() via Validator::resolver().
 */
class NvnValidator extends Validator
{
    public function validateEmail($attribute, $value, $parameters)
    {
        if (is_string($value) || (is_object($value) && method_exists($value, '__toString'))) {
            if (preg_match('/[\r\n]/', (string) $value)) {
                return false;
            }
        }

        return parent::validateEmail($attribute, $value, $parameters);
    }
}
