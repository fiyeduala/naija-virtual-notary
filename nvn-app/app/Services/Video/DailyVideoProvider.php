<?php

namespace App\Services\Video;

use App\Models\Session;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Daily.co video for the verification call.
 *
 * Two calls to Daily per join: provision (or reuse) a private room, then mint a
 * meeting token for this one participant. The room is private, so the URL on
 * its own opens nothing — a token is the only way in, and tokens are minted
 * server-side for people who have already passed authorizeParticipant().
 *
 * NOT RECORDED. Daily's API cannot be told "enable_recording: false" — the
 * property only accepts 'cloud', 'cloud-audio-only', 'local' and 'raw-tracks'.
 * So recording is off by omission: we never ask for it on the room and never
 * grant it on a token. The only thing that could turn it on without us is a
 * domain-wide default in the Daily dashboard, so every room is read back and
 * anything other than "off" is logged and audited. See config/video.php.
 *
 * Nothing here throws. If Daily is misconfigured, slow or down, the call screen
 * falls back to preview mode with a reason — a notary can still verify from the
 * uploaded ID and finish the job, which is much better than a 500 on the one
 * screen the client is sitting in front of.
 */
class DailyVideoProvider implements VideoProvider
{
    /** Reasons a call cannot start, safe to show a client. */
    public const NOT_CONFIGURED = 'not_configured';
    public const UNREACHABLE    = 'unreachable';

    public function name(): string
    {
        return 'daily';
    }

    /** Both halves are required: the key mints tokens, the domain builds the URL. */
    public function configured(): bool
    {
        return $this->apiKey() !== '' && $this->domain() !== '';
    }

    /**
     * The interface promises a room name, so an unreachable Daily still gets one
     * — it just labels the preview screen instead of hosting a call.
     */
    public function ensureRoom(Session $session): string
    {
        return $this->provisionRoom($session)
            ?? $session->video_room_id
            ?? $this->roomName($session);
    }

    public function joinCredentials(Session $session, User $user, string $role): array
    {
        // The notary of record and an admin acting as fallback run the call.
        $isOwner = in_array($role, ['notary', 'admin'], true);

        $credentials = [
            'provider'    => $this->name(),
            'room'        => $session->video_room_id ?: $this->roomName($session),
            'url'         => null,
            'token'       => null,
            'identity'    => (string) $user->id,
            'userName'    => $this->displayName($user),
            'role'        => $role,
            'isOwner'     => $isOwner,
            'unavailable' => null,
        ];

        if (! $this->configured()) {
            return array_merge($credentials, ['unavailable' => self::NOT_CONFIGURED]);
        }

        $room = $this->provisionRoom($session);

        if ($room === null) {
            return array_merge($credentials, ['unavailable' => self::UNREACHABLE]);
        }

        $token = $this->meetingToken($room, $user, $isOwner, $this->expiry($session));

        if ($token === null) {
            return array_merge($credentials, ['room' => $room, 'unavailable' => self::UNREACHABLE]);
        }

        return array_merge($credentials, [
            'room'  => $room,
            'url'   => $this->roomUrl($room),
            'token' => $token,
        ]);
    }

    /** Public URL of a room. Useless without a token — the rooms are private. */
    public function roomUrl(string $room): string
    {
        return 'https://' . $this->domain() . '/' . $room;
    }

    // ── Rooms ────────────────────────────────────────────────────────────────

    /**
     * Reuse the session's room when it is still joinable, extend it when it has
     * merely aged out, and cut a new one otherwise. Returns null when Daily
     * could not be reached — never a room name that does not exist.
     */
    private function provisionRoom(Session $session): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $expiry = $this->expiry($session);

        if ($name = $session->video_room_id) {
            $room = $this->send(fn (PendingRequest $r) => $r->get($this->endpoint("rooms/{$name}")));

            if ($room === null) {
                return null; // Daily unreachable — don't churn rooms over a blip.
            }

            if ($room->successful()) {
                $this->auditRecording($session, $name, $room->json());

                // Daily keeps expired rooms around; they just stop accepting
                // joins. Push the expiry out rather than minting a new id, so a
                // session picked up the next morning keeps one room in the log.
                if ((int) data_get($room->json(), 'config.exp', 0) > now()->addMinutes(5)->timestamp) {
                    return $name;
                }

                $extended = $this->send(fn (PendingRequest $r) => $r->post(
                    $this->endpoint("rooms/{$name}"),
                    ['properties' => ['exp' => $expiry]],
                ));

                if ($extended?->successful()) {
                    return $name;
                }
            } elseif ($room->status() !== 404) {
                // 401/403 is a bad key, 429 is a rate limit. Neither is fixed by
                // creating a second room, and doing so would leak rooms.
                $this->failed('room.lookup', $room, ['session_id' => $session->id, 'room' => $name]);

                return null;
            }
        }

        return $this->createRoom($session, $expiry);
    }

    private function createRoom(Session $session, int $expiry): ?string
    {
        $name = $this->roomName($session);

        $response = $this->send(fn (PendingRequest $r) => $r->post($this->endpoint('rooms'), [
            'name'    => $name,
            'privacy' => 'private',
            'properties' => [
                'exp' => $expiry,
                // An overrunning notarization is not cut off mid-signature;
                // the expiry only closes the door to new joins.
                'eject_at_room_exp'  => false,
                'enable_prejoin_ui'  => (bool) config('video.daily.prejoin_ui', true),
                // Private + no knocking: a meeting token is the only way in.
                'enable_knocking'    => false,
                // The written record is the message thread, which is retained.
                // In-call chat would be evidence that disappears with the room.
                'enable_chat'        => false,
                'enable_screenshare' => true,
                'max_participants'   => max(2, (int) config('video.daily.max_participants', 5)),
                // enable_recording is deliberately absent. See the class docblock.
            ],
        ]));

        if ($response === null || ! $response->successful()) {
            $this->failed('room.create', $response, ['session_id' => $session->id]);

            return null;
        }

        $session->update(['video_room_id' => $name]);
        $this->auditRecording($session, $name, $response->json());

        return $name;
    }

    // ── Tokens ───────────────────────────────────────────────────────────────

    private function meetingToken(string $room, User $user, bool $isOwner, int $expiry): ?string
    {
        $response = $this->send(fn (PendingRequest $r) => $r->post($this->endpoint('meeting-tokens'), [
            'properties' => [
                'room_name'          => $room,
                'user_name'          => $this->displayName($user),
                'user_id'            => (string) $user->id,
                'is_owner'           => $isOwner,
                'exp'                => $expiry,
                'start_video_off'    => false,
                'start_audio_off'    => false,
                'enable_screenshare' => true,
                // enable_recording is deliberately absent. See the class docblock.
            ],
        ]));

        if ($response === null || ! $response->successful()) {
            $this->failed('token.create', $response, ['room' => $room, 'user_id' => $user->id]);

            return null;
        }

        $token = $response->json('token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    /** Runs a request and turns transport failures into null instead of throwing. */
    private function send(callable $call): ?Response
    {
        try {
            return $call($this->client());
        } catch (ConnectionException $e) {
            Log::warning('[daily] could not reach Daily: ' . $e->getMessage());

            return null;
        } catch (\Throwable $e) {
            Log::error('[daily] request failed: ' . $e->getMessage());

            return null;
        }
    }

    private function client(): PendingRequest
    {
        $timeout = max(2, (int) config('video.daily.timeout', 6));

        return Http::withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout(min(4, $timeout))
            // Only a dropped connection is retried. Retrying a 4xx would create
            // duplicate rooms for an error that a second attempt cannot fix.
            ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('video.daily.api_url', 'https://api.daily.co/v1'), '/') . '/' . ltrim($path, '/');
    }

    /**
     * Recording should be off. If Daily says otherwise the cause is a
     * domain-level default in their dashboard, and someone needs to know: the
     * call screen promises the client it is not being recorded.
     */
    private function auditRecording(Session $session, string $room, ?array $payload): void
    {
        $setting = data_get($payload, 'config.enable_recording');

        if (blank($setting)) {
            return;
        }

        Log::warning("[daily] room {$room} reports recording enabled (" . json_encode($setting)
            . '). Naija Virtual Notary never requests recording — check for a domain-level '
            . 'default in the Daily dashboard.');

        AuditLogger::record('video.recording_enabled_unexpectedly', 'session', $session->id, [
            'room'             => $room,
            'enable_recording' => $setting,
        ]);
    }

    private function failed(string $stage, ?Response $response, array $context = []): void
    {
        Log::error('[daily] ' . $stage . ' failed', $context + [
            'status' => $response?->status(),
            // Daily puts a readable "info" field on its errors.
            'error'  => $response ? Str::limit((string) $response->body(), 500) : 'no response',
        ]);
    }

    /**
     * Joinable until this moment. Measured from the scheduled start so a room
     * booked for next Tuesday is not already dead when Tuesday arrives.
     */
    private function expiry(Session $session): int
    {
        $start = $session->scheduled_start_at?->isFuture()
            ? $session->scheduled_start_at
            : now();

        return $start->copy()
            ->addMinutes(max(15, (int) config('video.daily.room_ttl_minutes', 240)))
            ->timestamp;
    }

    private function roomName(Session $session): string
    {
        return 'nvn-' . $session->id . '-' . Str::lower(Str::random(8));
    }

    /** Shown on the tile. Daily caps user_id at 36 chars; ids are well under. */
    private function displayName(User $user): string
    {
        return Str::limit(trim((string) $user->full_name) ?: 'Participant', 40, '');
    }

    private function apiKey(): string
    {
        return trim((string) config('video.daily.api_key', ''));
    }

    /** Accepts 'yourteam' or 'yourteam.daily.co' and always returns a host. */
    private function domain(): string
    {
        $domain = trim((string) config('video.daily.domain', ''), " \t\n\r\0\x0B/");

        if ($domain === '') {
            return '';
        }

        $domain = preg_replace('#^https?://#i', '', $domain);

        return str_contains($domain, '.') ? $domain : $domain . '.daily.co';
    }
}
