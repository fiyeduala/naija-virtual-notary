<?php

namespace App\Console\Commands;

use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Services\MetaConversionsService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Says which of the ways Meta tracking can be silently broken this one is.
 *
 * Every failure mode here looks identical from the outside — the ad runs, the
 * money comes in, and Events Manager shows nothing — so guessing between them
 * costs a week of spend. This asks Meta directly, and sends a real test event
 * if asked to.
 *
 *   php artisan nvn:meta-check
 *   php artisan nvn:meta-check --send   (fires a test Purchase)
 */
class MetaCheck extends Command
{
    protected $signature = 'nvn:meta-check {--send : Send a test event to the dataset}';

    protected $description = 'Check the Meta dataset, token and attribution plumbing';

    public function handle(MetaConversionsService $meta): int
    {
        $dataset = trim((string) config('nvn.meta.dataset_id', ''));
        $token   = trim((string) config('nvn.meta.access_token', ''));
        $version = trim((string) config('nvn.meta.version', 'v23.0'), '/ ');
        $test    = trim((string) config('nvn.meta.test_event_code', ''));

        // ── 1. Is it switched on at all? ─────────────────────────────────────
        if ($dataset === '' || $token === '') {
            $this->error('Meta tracking is off.');
            $this->line('  META_DATASET_ID  ' . ($dataset === '' ? '<fg=red>missing</>' : 'set'));
            $this->line('  META_CAPI_TOKEN  ' . ($token === '' ? '<fg=red>missing</>' : 'set'));
            $this->newLine();
            $this->line('  Set both in .env on the server, then run <options=bold>php artisan config:cache</>.');
            $this->line('  Until then no pixel loads, no cookie is written and no event is sent —');
            $this->line('  and conversions cannot be backfilled, so do this before the ad spends.');

            return self::FAILURE;
        }

        $this->line("Dataset:  <options=bold>{$dataset}</>");
        $this->line("Version:  {$version}");
        $this->line('Token:    ' . Str::limit($token, 12, '…') . ' (' . strlen($token) . ' chars)');

        if ($test !== '') {
            $this->warn("Test event code {$test} is set — events go to the Test Events tab and do NOT optimise.");
        }

        $this->newLine();

        // ── 2. Does the token actually open this dataset? ────────────────────
        try {
            $response = Http::timeout(15)
                ->withQueryParameters(['access_token' => $token, 'fields' => 'name,id'])
                ->get("https://graph.facebook.com/{$version}/{$dataset}");
        } catch (ConnectionException $e) {
            $this->error('Could not reach graph.facebook.com: ' . $e->getMessage());
            $this->line('  The server may be blocking outbound HTTPS. Conversions will queue and fail.');

            return self::FAILURE;
        }

        // Reading the dataset is a convenience, not the capability under test.
        // Describing a dataset needs ads_read on the asset; posting events to it
        // does not, and a token minted from the dataset page is scoped to send
        // and nothing else. Failing the whole check here would condemn a working
        // install — so this warns, and --send still gets to prove the real path.
        $canRead = $response->successful();

        if (! $canRead) {
            $error = $response->json('error') ?? [];
            $message = $error['message'] ?? Str::limit($response->body(), 300);

            $this->warn("Meta would not describe this dataset (HTTP {$response->status()}): {$message}");
            $this->newLine();
            $this->line('  <options=bold>What that usually means</>');
            $this->line('  · "Missing Permission" — most likely nothing is wrong with sending. Reading');
            $this->line('    a dataset needs ads_read on the asset; posting events to it does not.');
            $this->line('    Run this again with <options=bold>--send</> — that is the only capability the app uses.');
            $this->line('  · "Unsupported get request" or an unknown path — the dataset ID is wrong,');
            $this->line('    or the System User was never given access to this dataset.');
            $this->line('  · "Session has expired" / "Invalid OAuth" — the token was a short-lived one');
            $this->line('    from the dataset page. Mint a System User token instead; it does not expire.');
            $this->line("  · A complaint about version {$version} — Meta has retired it. Read the current");
            $this->line('    version off the Conversions API tab and set META_API_VERSION.');
        } else {
            $this->info('Token opens the dataset: ' . ($response->json('name') ?? $dataset));
        }

        // ── 3. Is anything actually being attributed? ────────────────────────
        $this->newLine();

        $withClick = NotarizationRequest::whereNotNull('attribution')->count();
        $total     = NotarizationRequest::count();

        $this->line("Requests carrying an ad click: <options=bold>{$withClick}</> of {$total}");

        if ($withClick === 0 && $total > 0) {
            $this->warn('  None yet. That is expected before the first ad runs. Once it has,');
            $this->warn('  a zero here means the pixel is not loading on the landing page —');
            $this->warn('  check for _fbc in the browser cookies after clicking your own advert.');
        }

        $offlinePaid = Payment::where('type', 'request_fee')
            ->where('status', 'successful')
            ->whereNotNull('settlement_method')
            ->count();

        if ($offlinePaid > 0) {
            $this->line("Bank transfers / cash settled: {$offlinePaid}");
            $this->line('  These report a conversion only where an ad click was recorded on the request.');
        }

        // ── 4. Optionally prove it end to end ────────────────────────────────
        if ($this->option('send')) {
            $this->newLine();

            if ($test === '') {
                $this->warn('No META_TEST_EVENT_CODE set — this will send a REAL Purchase to the dataset.');

                if (! $this->confirm('Send it anyway?', false)) {
                    return self::SUCCESS;
                }
            }

            $sent = $meta->send(
                event: 'Purchase',
                eventId: 'nvn-meta-check-' . now()->timestamp,
                attribution: [
                    'fbp' => 'fb.1.' . now()->timestamp . '.1234567890',
                    'ip'  => '105.112.0.1',
                    'user_agent' => 'nvn-meta-check',
                    'url' => config('app.url'),
                ],
                custom: ['value' => 1.00, 'currency' => 'NGN'],
            );

            if ($sent) {
                $this->info('Test event accepted. It should appear in Events Manager within a minute or two.');

                if (! $canRead) {
                    $this->line('  The dataset would not describe itself earlier, but sending is what');
                    $this->line('  matters and sending works. Nothing further is needed.');
                }
            } else {
                $this->error('Test event was not accepted — see storage/logs for the [meta] line.');
            }

            return $sent ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('Run with <options=bold>--send</> to fire a test event and prove the whole path.');

        return $canRead ? self::SUCCESS : self::FAILURE;
    }
}
