<?php

namespace App\Notifications\Admin;

use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Somebody signed up.
 *
 * Deliberately no mail channel. A signup is worth glancing at, not worth an
 * email — and at any real volume a mailed copy of every registration is the
 * thing that trains an admin to stop reading admin mail at all.
 */
class NewUserRegisteredNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->user->id,
            'name'    => $this->user->full_name,
            'email'   => $this->user->email,
            'role'    => $this->user->role?->value,
            'type'    => 'user_registered',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'New sign-up',
            'body'  => $this->user->full_name . ' — ' . $this->user->email,
            'url'   => route('filament.admin.resources.users.index'),
        ];
    }
}
