<?php

namespace App\Services\Video;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Template for a real provider (Agora shown). This is a SKELETON: it returns
 * the App ID, channel, and the data the Agora web SDK expects, but the RTC
 * token must be generated with Agora's token builder.
 *
 * To finish in production:
 *   composer require boogie/agora-token-builder   (or Agora's official builder)
 * then build a token in tokenFor() with the App Certificate, channel, uid, and
 * an expiry. Recording is intentionally NOT enabled.
 *
 * Activate by setting VIDEO_PROVIDER=agora and binding this class in
 * VideoServiceProvider (see config/video.php).
 */
class AgoraVideoProvider implements VideoProvider
{
    public function ensureRoom(Session $session): string
    {
        if ($session->video_room_id) {
            return $session->video_room_id;
        }

        // Agora "channel name"
        $channel = 'nvn_' . $session->id . '_' . Str::lower(Str::random(6));
        $session->update(['video_room_id' => $channel]);

        return $channel;
    }

    public function joinCredentials(Session $session, User $user, string $role): array
    {
        $channel = $this->ensureRoom($session);
        $uid = (int) $user->id;

        return [
            'provider' => $this->name(),
            'appId'    => config('video.agora.app_id'),
            'channel'  => $channel,
            'uid'      => $uid,
            'token'    => $this->tokenFor($channel, $uid),
            'role'     => $role,
        ];
    }

    /**
     * Build an Agora RTC token. Returns null until the token builder is wired,
     * which keeps the UI in preview mode rather than failing.
     */
    private function tokenFor(string $channel, int $uid): ?string
    {
        $appId = config('video.agora.app_id');
        $cert  = config('video.agora.app_certificate');

        if (! $appId || ! $cert) {
            return null;
        }

        // TODO: use Agora's RtcTokenBuilder here, e.g.:
        // return RtcTokenBuilder::buildTokenWithUid($appId, $cert, $channel, $uid, RtcTokenBuilder::RolePublisher, now()->addHour()->timestamp);
        return null;
    }

    public function name(): string
    {
        return 'agora';
    }
}
