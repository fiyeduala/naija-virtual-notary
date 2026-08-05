<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Deployment check for the verification call. Proves the credentials work by
 * doing the real thing end to end — create a private room, mint a meeting
 * token, delete the room — and, just as importantly, checks that nobody has
 * switched recording on at the Daily domain level.
 *
 *   php artisan nvn:video-check
 */
class VideoCheck extends Command
{
    protected $signature = 'nvn:video-check';

    protected $description = 'Verify the video provider credentials and confirm recording is off';

    public function handle(): int
    {
        $provider = (string) config('video.provider');
        $this->line("Provider: <options=bold>{$provider}</>");

        if ($provider !== 'daily') {
            $this->warn('Not using Daily — nothing to check. Set VIDEO_PROVIDER=daily in .env for live calls.');

            return self::SUCCESS;
        }

        $key    = trim((string) config('video.daily.api_key', ''));
        $domain = trim((string) config('video.daily.domain', ''), " \t\n\r\0\x0B/");

        if ($key === '' || $domain === '') {
            $this->error('DAILY_API_KEY and DAILY_DOMAIN must both be set in .env.');
            $this->line('  The call screen stays in preview mode until they are — nothing else is blocked.');

            return self::FAILURE;
        }

        $this->line('Domain:   ' . (str_contains($domain, '.') ? $domain : $domain . '.daily.co'));
        $this->newLine();

        $base = rtrim((string) config('video.daily.api_url', 'https://api.daily.co/v1'), '/');
        $http = Http::withToken($key)->acceptJson()->asJson()->timeout(15);

        // ── 1. The key works, and what the domain defaults to ────────────────
        try {
            $me = $http->get($base . '/');
        } catch (ConnectionException $e) {
            $this->error('Could not reach Daily: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($me->status() === 401 || $me->status() === 403) {
            $this->error('Daily rejected the API key (HTTP ' . $me->status() . '). Check DAILY_API_KEY.');

            return self::FAILURE;
        }

        if (! $me->successful()) {
            $this->error('Daily returned HTTP ' . $me->status() . ': ' . Str::limit($me->body(), 200));

            return self::FAILURE;
        }

        $this->info('✓ API key accepted' . ($me->json('domain_name') ? ' for ' . $me->json('domain_name') : ''));

        $domainRecording = data_get($me->json(), 'config.enable_recording');
        $recordingWarned = false;

        if (filled($domainRecording)) {
            $recordingWarned = true;
            $this->newLine();
            $this->error('✗ RECORDING IS ENABLED ON YOUR DAILY DOMAIN (' . json_encode($domainRecording) . ')');
            $this->line('  Every room inherits this. The call screen tells the client the session is not');
            $this->line('  recorded, and Daily has no way to override a domain default per room.');
            $this->line('  Turn it off: Daily dashboard → your domain → Settings → Recording.');
        } else {
            $this->info('✓ Recording is off at the domain level');
        }

        // ── 2. Create a private room, exactly as the app does ────────────────
        $name = 'nvn-check-' . Str::lower(Str::random(8));

        $room = $http->post($base . '/rooms', [
            'name'    => $name,
            'privacy' => 'private',
            'properties' => [
                'exp'                => now()->addMinutes(10)->timestamp,
                'eject_at_room_exp'  => false,
                'enable_prejoin_ui'  => (bool) config('video.daily.prejoin_ui', true),
                'enable_knocking'    => false,
                'enable_chat'        => false,
                'enable_screenshare' => true,
                'max_participants'   => max(2, (int) config('video.daily.max_participants', 5)),
            ],
        ]);

        if (! $room->successful()) {
            $this->error('✗ Could not create a room (HTTP ' . $room->status() . '): ' . Str::limit($room->body(), 300));

            return self::FAILURE;
        }

        $this->info('✓ Created a private test room');

        $roomRecording = data_get($room->json(), 'config.enable_recording');

        if (filled($roomRecording) && ! $recordingWarned) {
            $recordingWarned = true;
            $this->error('✗ The test room came back with recording enabled (' . json_encode($roomRecording) . ')');
        }

        // ── 3. Mint a meeting token, as a notary would get ───────────────────
        $token = $http->post($base . '/meeting-tokens', [
            'properties' => [
                'room_name' => $name,
                'user_name' => 'Video check',
                'user_id'   => 'check',
                'is_owner'  => true,
                'exp'       => now()->addMinutes(10)->timestamp,
            ],
        ]);

        $minted = $token->successful() && filled($token->json('token'));

        if ($minted) {
            $this->info('✓ Minted a meeting token');
        } else {
            $this->error('✗ Could not mint a meeting token (HTTP ' . $token->status() . '): '
                . Str::limit($token->body(), 300));
        }

        // ── 4. Clean up after ourselves ──────────────────────────────────────
        $deleted = $http->delete($base . '/rooms/' . $name)->successful();

        $deleted
            ? $this->info('✓ Deleted the test room')
            : $this->warn('! Could not delete the test room "' . $name . '" — remove it from the dashboard.');

        $this->newLine();

        if (! $minted) {
            return self::FAILURE;
        }

        if ($recordingWarned) {
            $this->warn('Video works, but fix the recording setting before taking real clients.');

            return self::FAILURE;
        }

        $this->info('Video is ready. Calls are live and nothing is recorded.');

        return self::SUCCESS;
    }
}
