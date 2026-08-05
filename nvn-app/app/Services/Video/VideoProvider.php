<?php

namespace App\Services\Video;

use App\Models\Session;
use App\Models\User;

/**
 * Provider-agnostic video interface. Swap the concrete implementation
 * (Agora, Zoom Video SDK, Daily, etc.) in config/video.php without touching
 * the rest of the app.
 *
 * The verification call is NOT recorded — implementations must not enable
 * recording. The defensible evidence is the VerificationRecord + audit log.
 */
interface VideoProvider
{
    /** Provision (or reuse) a room for a session and return its identifier. */
    public function ensureRoom(Session $session): string;

    /**
     * Issue the credentials a participant's browser needs to join.
     * Returns an array consumed by the front-end (shape varies by provider).
     */
    public function joinCredentials(Session $session, User $user, string $role): array;

    /** A short machine name, e.g. 'agora', 'daily', 'manual'. */
    public function name(): string;
}
