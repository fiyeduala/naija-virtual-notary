<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Message;
use App\Models\NotarizationRequest;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Support\AdminAlert;
use App\Support\AuditLogger;

/**
 * Central place for posting per-request messages.
 *
 * Two-way between the client and the assigned notary, with admin able to read
 * every thread and post into any of them. Admin messages are attributed to the
 * platform ("Naija Virtual Notary / Support"), never silently under the
 * notary's name (see Build Plan section 4.5).
 */
class MessagingService
{
    public function post(NotarizationRequest $request, User $sender, string $body): Message
    {
        $senderRole = $sender->role; // client | notary | admin
        $recipient = $this->resolveRecipient($request, $sender);

        $message = Message::create([
            'request_id'        => $request->id,
            'sender_user_id'    => $sender->id,
            'sender_role'       => $senderRole,
            'recipient_user_id' => $recipient?->id,
            'body'              => $body,
        ]);

        AuditLogger::record('message.sent', 'notarization_request', $request->id, [
            'message_id'  => $message->id,
            'sender_role' => $senderRole->value,
        ], $sender->id);

        // Notify the recipient (email + in-app). Admin oversight messages still
        // notify the client; a client's message notifies the assigned notary
        // (or the admin handling a fallback).
        if ($recipient) {
            $recipient->notify(new NewMessageNotification($request, $message));
        } elseif ($senderRole === UserRole::Client) {
            // Nobody is assigned and no admin has spoken yet — a client asking
            // about a request that has not been paid for. The whole desk hears
            // it rather than the message sitting in a thread nobody opens.
            AdminAlert::send(new NewMessageNotification($request, $message));
        }

        return $message;
    }

    /**
     * Who receives a message depends on who sent it:
     *  - client  → the assigned notary, or the admin handling a fallback
     *  - notary  → the client
     *  - admin   → the client (admin is stepping into the conversation)
     *
     * A client can also be replying about a request that has no notary at all —
     * an unpaid draft an admin has followed up on. Before the follow-up feature
     * nothing could arrive in that state, so the null fell through silently and
     * the reply notified nobody. It now goes to whichever admin last spoke in
     * the thread, and post() alerts the whole desk when even that is empty.
     */
    private function resolveRecipient(NotarizationRequest $request, User $sender): ?User
    {
        if ($sender->role === UserRole::Client) {
            // Prefer the admin handling a fallback, else the assigned notary.
            if ($request->handled_by) {
                return User::find($request->handled_by);
            }

            if ($request->notary?->user) {
                return $request->notary->user;
            }

            return $this->lastAdminInThread($request);
        }

        // Notary or admin → the client
        return $request->client;
    }

    /** The admin who last posted here, so a reply lands with whoever wrote. */
    private function lastAdminInThread(NotarizationRequest $request): ?User
    {
        $id = Message::where('request_id', $request->id)
            ->where('sender_role', UserRole::Admin->value)
            ->whereNotNull('sender_user_id')
            ->latest('id')
            ->value('sender_user_id');

        return $id ? User::find($id) : null;
    }

    /** Mark all messages in a thread as read for the given viewer. */
    public function markReadFor(NotarizationRequest $request, User $viewer): void
    {
        Message::where('request_id', $request->id)
            ->where('recipient_user_id', $viewer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
