<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Services\MessagingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function __construct(private MessagingService $messaging) {}

    /** The per-request thread for the client or the assigned notary. */
    public function show(NotarizationRequest $request): View
    {
        $this->authorizeParticipant($request);

        $request->load(['messages.sender', 'client', 'notary.user']);
        $this->messaging->markReadFor($request, Auth::user());

        return view('messaging.thread', [
            'request'  => $request,
            'messages' => $request->messages()->with('sender')->orderBy('created_at')->get(),
            // An admin can land here as the fallback handler. They now have
            // access to the notary screens too, but the thread list they came
            // from is the admin one, so that is where "back" belongs.
            'backRoute'=> match (true) {
                Auth::user()->isClient() => route('client.dashboard'),
                Auth::user()->isAdmin()  => route('admin.messages.index'),
                default                  => route('notary.requests.incoming'),
            },
        ]);
    }

    public function store(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeParticipant($request);

        $validated = $http->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->messaging->post($request, Auth::user(), $validated['body']);

        return redirect()->route('messages.show', $request);
    }

    /** Client (owner) or the assigned notary may use this thread. */
    private function authorizeParticipant(NotarizationRequest $request): void
    {
        $user = Auth::user();
        $allowed = $user->id === $request->client_id
            || $user->id === $request->notary?->user_id
            || $user->id === $request->handled_by;

        abort_unless($allowed, 403);
    }
}
