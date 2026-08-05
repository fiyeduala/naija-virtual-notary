<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\NotarizationRequest;
use App\Services\MessagingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin oversight of messaging: see every thread platform-wide, open any of
 * them, and post into any of them (e.g. to answer a client when the assigned
 * notary is unavailable). Admin messages are attributed to the platform.
 */
class MessageOversightController extends Controller
{
    public function __construct(private MessagingService $messaging) {}

    /** All requests that have at least one message, newest activity first. */
    public function index(Request $http): View
    {
        $query = NotarizationRequest::query()
            ->whereHas('messages')
            ->with(['client', 'notary.user'])
            ->withCount('messages')
            ->withMax('messages', 'created_at');

        if ($term = trim((string) $http->query('q'))) {
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', "%{$term}%")
                  ->orWhereHas('client', fn ($c) => $c->where('full_name', 'like', "%{$term}%"));
            });
        }

        $threads = $query->orderByDesc('messages_max_created_at')->paginate(20);

        return view('admin.messages.index', ['threads' => $threads, 'q' => $http->query('q')]);
    }

    public function show(NotarizationRequest $request): View
    {
        $messages = $request->messages()->with('sender')->orderBy('created_at')->get();

        return view('admin.messages.show', [
            'request'  => $request->load('client', 'notary.user', 'handledBy'),
            'messages' => $messages,
        ]);
    }

    /** Admin steps into the thread. */
    public function store(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $validated = $http->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->messaging->post($request, Auth::user(), $validated['body']);

        return redirect()->route('admin.messages.show', $request)
            ->with('status', 'Message sent to the client.');
    }
}
