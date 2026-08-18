<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Mint the VAPID keypair that signs every web push.
 *
 * config/nvn.php has pointed at this command since push was built, but the
 * command itself never existed — so the one step that had to be done on the
 * server was the one step there was no way to do.
 *
 * The pair identifies this server to Google, Apple and Mozilla's push
 * services. Change it and every existing subscription stops working, because
 * a subscription is bound to the public key it was created with, so generate
 * once and keep it. It is not a secret that unlocks anything on its own, but
 * the private half must not reach the browser.
 *
 *   php artisan nvn:vapid-keys
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'nvn:vapid-keys {--force : Print a new pair even though keys are already configured}';

    protected $description = 'Generate the VAPID keypair for web push notifications';

    public function handle(): int
    {
        $existing = trim((string) config('nvn.vapid_public_key', ''));

        if ($existing !== '' && ! $this->option('force')) {
            $this->warn('Keys are already configured for this environment.');
            $this->line('  Public key: ' . substr($existing, 0, 16) . '…');
            $this->newLine();
            $this->line('Replacing them invalidates every existing subscription — everyone who');
            $this->line('turned alerts on would have to turn them on again. Pass --force if that');
            $this->line('is really what you want.');

            return self::SUCCESS;
        }

        $keys = $this->generate();

        if ($keys === null) {
            return self::FAILURE;
        }

        $this->info('Add these two lines to .env, then run: php artisan config:cache');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->newLine();
        $this->line('The public key is handed to the browser and is fine in a page source.');
        $this->line('The private key signs the sends — keep it in .env and nowhere else.');
        $this->newLine();
        $this->line('Then check it with: php artisan nvn:push-check');

        return self::SUCCESS;
    }

    /**
     * A P-256 keypair, however this machine can manage one.
     *
     * The library's own generator goes through PHP's OpenSSL binding, which
     * cannot create an EC key at all when the install has no openssl.cnf to
     * point at — the failure is a bare "Unable to create the key" with a stack
     * trace, on the one command that has to work on a server you may only be
     * able to reach through a control panel. So the openssl binary is the
     * second try, and an explanation is the third.
     *
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function generate(): ?array
    {
        try {
            return VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->line('<fg=gray>PHP could not generate the key itself (' . $e->getMessage()
                . '); trying the openssl command.</>');
        }

        if ($keys = $this->generateWithOpenSslBinary()) {
            return $keys;
        }

        $this->error('Could not generate a keypair on this machine.');
        $this->newLine();
        $this->line('PHP\'s OpenSSL cannot create an EC key here, and the openssl command is');
        $this->line('not available either. Generate the pair anywhere that has openssl —');
        $this->line('any Linux or Mac terminal — and paste the result into .env:');
        $this->newLine();
        $this->line('  openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem');
        $this->line('  openssl ec -in vapid.pem -text -noout');
        $this->newLine();
        $this->line('Then run this command again on that machine, or ask for the two values');
        $this->line('to be converted from the hex it prints.');

        return null;
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    private function generateWithOpenSslBinary(): ?array
    {
        $pem = @shell_exec('openssl ecparam -name prime256v1 -genkey -noout 2>&1');

        if (! is_string($pem) || ! str_contains($pem, 'BEGIN EC PRIVATE KEY')) {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'vapid');

        try {
            file_put_contents($file, $pem);
            $text = @shell_exec('openssl ec -in ' . escapeshellarg($file) . ' -text -noout 2>&1');
        } finally {
            @unlink($file);
        }

        if (! is_string($text)) {
            return null;
        }

        // Both halves come out as colon-separated hex across several lines.
        // The private scalar is 32 bytes, the public point 65 and begins 04
        // (uncompressed) — anything else is not what the push services expect.
        $private = $this->extractHex($text, 'priv');
        $public  = $this->extractHex($text, 'pub');

        if ($private === null || $public === null
            || strlen($private) !== 32
            || strlen($public) !== 65
            || $public[0] !== "\x04") {
            return null;
        }

        return [
            'publicKey'  => $this->base64Url($public),
            'privateKey' => $this->base64Url($private),
        ];
    }

    /** The binary behind a "priv:" or "pub:" block of colon-separated hex. */
    private function extractHex(string $text, string $label): ?string
    {
        if (! preg_match('/' . $label . ':\s*\n((?:\s*[0-9a-f]{2}(?::[0-9a-f]{2})*:?\s*\n)+)/i', $text, $m)) {
            return null;
        }

        $hex = preg_replace('/[^0-9a-f]/i', '', $m[1]);
        $bin = @hex2bin($hex);

        return $bin === false ? null : $bin;
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
