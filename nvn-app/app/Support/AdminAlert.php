<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Alerts that belong to the desk, not to a person.
 *
 * The older code notified `User::where('role', Admin)->first()`, which quietly
 * meant "whoever was created first". These go to every admin, because the point
 * of pushing them to a phone is that whoever is holding a phone sees them.
 */
class AdminAlert
{
    public static function send(Notification $notification): void
    {
        $admins = User::query()->admins()->get();

        if ($admins->isNotEmpty()) {
            Notifier::send($admins, $notification);
        }
    }
}
