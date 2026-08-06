<?php

namespace App\Support;

/**
 * Verifies a password against a hash produced by WordPress.
 *
 * Nothing here ever creates a WordPress hash — this exists only so that a
 * person who had an account on the old WordPress site can sign in to this one
 * with the password they already know, once, after which the account is
 * upgraded to a normal Laravel hash and the WordPress hash is thrown away.
 *
 * WordPress has used three schemes, and a site that has been running for years
 * will have all three sitting in wp_users at the same time:
 *
 *  '$wp$2y$…'  WordPress 6.8 and later. bcrypt, but not over the password:
 *              over base64(HMAC-SHA384(password, 'wp-sha384')), so that a
 *              password longer than bcrypt's 72-byte limit is not silently
 *              truncated. The '$wp' prefix is stripped before password_verify.
 *  '$P$…'      phpass portable hashes — WordPress's own since 2008, and still
 *              what most accounts on an older site have. Salted MD5, iterated
 *              2^n times. Implemented below because no PHP extension does it.
 *  32 hex      A bare MD5 from WordPress 2.4 or earlier, kept working by
 *              WordPress ever since. Accepted, because refusing it would lock
 *              out the oldest accounts on the site — and it is upgraded to a
 *              modern hash the moment it is used.
 *
 * A '$2y$' hash with no prefix is also accepted: that is what some migration
 * plugins leave behind.
 */
class WordPressHasher
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function check(string $password, ?string $hash): bool
    {
        $hash = (string) $hash;

        if ($hash === '' || $password === '') {
            return false;
        }

        // WordPress 6.8+ bcrypt.
        if (str_starts_with($hash, '$wp$')) {
            $prehashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

            return password_verify($prehashed, substr($hash, 3));
        }

        // phpass portable. '$H$' is the same format under phpBB's identifier;
        // WordPress accepts it and so does this.
        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return hash_equals($hash, static::phpass($password, $hash));
        }

        // A plain bcrypt/argon hash, left by some migration plugins.
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$argon2')) {
            return password_verify($password, $hash);
        }

        // Pre-2.5 WordPress: a bare MD5, no salt.
        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            return hash_equals($hash, md5($password));
        }

        return false;
    }

    /** Whether this looks like something check() could even attempt. */
    public static function recognises(?string $hash): bool
    {
        $hash = (string) $hash;

        return str_starts_with($hash, '$wp$')
            || str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')
            || str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$argon2')
            || (strlen($hash) === 32 && ctype_xdigit($hash));
    }

    /**
     * phpass's crypt_private, reimplemented.
     *
     * Returns the full hash string so the caller can compare it against the
     * stored one; an unusable setting string returns '*', which never matches.
     */
    private static function phpass(string $password, string $setting): string
    {
        $countLog2 = strpos(self::ITOA64, $setting[3] ?? '');

        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return '*';
        }

        $salt = substr($setting, 4, 8);

        if (strlen($salt) !== 8) {
            return '*';
        }

        $count = 1 << $countLog2;
        $hash  = md5($salt . $password, true);

        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        return substr($setting, 0, 12) . static::encode64($hash, 16);
    }

    /** phpass's own base64 variant — not the standard alphabet or padding. */
    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i      = 0;

        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3f];

            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }

            $output .= self::ITOA64[($value >> 6) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }

            $output .= self::ITOA64[($value >> 12) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            $output .= self::ITOA64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $output;
    }
}
