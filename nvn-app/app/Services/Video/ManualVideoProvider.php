<?php

namespace App\Services\Video;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Placeholder provider. Generates a stable room id and returns no real token,
 * so the session UI works end-to-end before a paid SDK is connected. Replace
 * with a real provider (see AgoraVideoProvider as a template) in production.
 *
 * The front-end treats a null token as "preview mode" and shows a notice
 * instead of a live call, so the rest of the notarization flow is testable.
 */
class ManualVideoProvider implements VideoProvider
{
    public function ensureRoom(Session $session): string
    {
        if ($session->video_room_id) {
            return $session->video_room_id;
        }

        $room = 'nvn-' . $session->id . '-' . Str::lower(Str::random(8));
        $session->update(['video_room_id' => $room]);

        return $room;
    }

    public function joinCredentials(Session $session, User $user, string $role): array
    {
        return [
            'provider' => $this->name(),
            'room'     => $this->ensureRoom($session),
            'token'    => null, // no live call in preview mode
            'identity' => (string) $user->id,
            'role'     => $role,
        ];
    }

    public function name(): string
    {
        return 'manual';
    }
}
