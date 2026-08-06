<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;

/**
 * The unsubscribe link at the foot of a broadcast.
 *
 * Reached through a signed URL, so nobody can unsubscribe someone else by
 * editing the id, and no login is required — asking a person to sign in before
 * they may stop receiving email is the reason people press "spam" instead.
 *
 * This only switches off announcements. Email about someone's own notarization
 * — their document is ready, their session is starting — is not marketing and
 * keeps flowing; the page says so plainly.
 */
class EmailPreferencesController extends Controller
{
    public function unsubscribe(User $user)
    {
        if (! $user->bulk_email_opt_out) {
            $user->forceFill(['bulk_email_opt_out' => true])->save();

            AuditLogger::record('user.bulk_email_opt_out', 'user', $user->id, [
                'via' => 'unsubscribe_link',
            ], $user->id);
        }

        return view('emails.unsubscribed', [
            'user'     => $user,
            'resubscribed' => false,
        ]);
    }

    /** The "that was a mistake" link on the confirmation page. */
    public function resubscribe(User $user)
    {
        if ($user->bulk_email_opt_out) {
            $user->forceFill(['bulk_email_opt_out' => false])->save();

            AuditLogger::record('user.bulk_email_opt_in', 'user', $user->id, [
                'via' => 'unsubscribe_link',
            ], $user->id);
        }

        return view('emails.unsubscribed', [
            'user'         => $user,
            'resubscribed' => true,
        ]);
    }
}
