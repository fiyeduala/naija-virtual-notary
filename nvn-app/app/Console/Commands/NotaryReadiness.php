<?php

namespace App\Console\Commands;

use App\Models\NotaryProfile;
use Illuminate\Console\Command;

/**
 * What each notary is still missing before they can take a booking.
 *
 * Written for the WordPress migration, where six notaries arrived with
 * whatever the old partner form happened to collect, and the answer to "who do
 * I need to chase, and for what?" was otherwise a manual read of four tables.
 * It outlives the migration though — a notary who signs up here and stops
 * halfway through onboarding leaves exactly the same gaps.
 *
 * Read-only. Prints; changes nothing.
 */
class NotaryReadiness extends Command
{
    protected $signature = 'nvn:notary-readiness
        {--incomplete : Only show notaries with something missing}
        {--emails : Print just the email addresses, for pasting into a mail client}';

    protected $description = 'List every notary and what they still need before they can work';

    public function handle(): int
    {
        $profiles = NotaryProfile::with(['user', 'assets', 'credentials', 'bankDetails'])
            ->get()
            ->sortBy(fn (NotaryProfile $p) => $p->user?->email ?? '');

        if ($profiles->isEmpty()) {
            $this->info('No notary profiles.');

            return self::SUCCESS;
        }

        $rows    = [];
        $chasing = [];

        foreach ($profiles as $profile) {
            $missing = $this->missing($profile);

            if ($missing !== [] || ! $this->option('incomplete')) {
                $rows[] = [
                    $profile->user?->email ?? '(no account)',
                    $profile->is_system_native ? 'system' : $profile->verification_status,
                    $profile->canSeal() ? 'yes' : 'no',
                    $missing === [] ? '—' : implode(', ', $missing),
                ];
            }

            if ($missing !== [] && $profile->user?->email) {
                $chasing[] = $profile->user->email;
            }
        }

        if ($this->option('emails')) {
            $this->line(implode(', ', $chasing));

            return self::SUCCESS;
        }

        $this->table(['notary', 'status', 'can seal', 'still needed'], $rows);

        $this->line(sprintf(
            '  %d of %d can seal. %d need chasing.',
            $profiles->filter->canSeal()->count(),
            $profiles->count(),
            count($chasing)
        ));

        if ($chasing !== []) {
            $this->newLine();
            $this->line('  Addresses to write to:');
            $this->line('    ' . implode(', ', $chasing));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function missing(NotaryProfile $profile): array
    {
        $missing = [];

        // The system-native notary is the platform acting as its own notary
        // ([[system-native-notary]]): there is no certificate to collect and no
        // bank account to pay, because the money is already here. Only the
        // marks it signs with are worth asking about.
        if ($profile->is_system_native) {
            return $this->sealingGaps($profile);
        }

        // A display name that is really a username. WordPress falls back to
        // user_login when first_name and last_name are empty, so several
        // imported notaries are called things like "pebiala" — which is what a
        // client would see on the certificate and in the listing.
        $name = trim((string) $profile->user?->full_name);

        if ($name === '' || ! str_contains($name, ' ')) {
            $missing[] = 'full name';
        }

        if (blank($profile->user?->phone)) {
            $missing[] = 'phone';
        }

        $missing = array_merge($missing, $this->sealingGaps($profile));

        $credentials = $profile->credentials->pluck('type')->all();

        foreach (['valid_id', 'notary_certificate'] as $type) {
            if (! in_array($type, $credentials, true)) {
                $missing[] = str_replace('_', ' ', $type);
            }
        }

        // Not a blocker for sealing, but it is a blocker for being paid, and
        // that surfaces at the worst possible moment — after the work is done.
        if (! $profile->bankDetails) {
            $missing[] = 'bank details';
        }

        return $missing;
    }

    /**
     * Which of the three marks are absent, named individually.
     *
     * `canSeal()` answers yes or no, which is the right answer for the
     * application and the wrong one for an email: it does not tell you whether
     * to ask somebody for one file or for three.
     *
     * @return list<string>
     */
    private function sealingGaps(NotaryProfile $profile): array
    {
        $held = $profile->assets
            ->filter(fn ($a) => filled($a->file_url))
            ->pluck('type')
            ->all();

        return array_values(array_filter(
            NotaryProfile::SEALING_ASSETS,
            fn (string $type) => ! in_array($type, $held, true)
        ));
    }
}
