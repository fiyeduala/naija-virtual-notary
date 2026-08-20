<?php

namespace App\Http\Controllers\Client;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ClientDashboardController extends Controller
{
    public function index(): View
    {
        $userId = Auth::id();

        // Submitted (= awaiting payment) is included deliberately. It is not in
        // RequestStatus::active(), and it is not a draft either, so a client who
        // abandoned at the payment step used to see their request vanish from the
        // dashboard entirely with no way back to it.
        $activeStatuses = array_map(
            fn ($s) => $s->value,
            array_merge([RequestStatus::Submitted], RequestStatus::active()),
        );

        $active = NotarizationRequest::where('client_id', $userId)
            ->whereIn('status', $activeStatuses)
            // service and documents feed isCategoryBlocked() on every row, which
            // otherwise costs three queries a request just to decide whether to
            // draw a banner.
            ->with('notary.user', 'session', 'service', 'documents')
            ->latest()
            ->get();

        $drafts = NotarizationRequest::where('client_id', $userId)
            ->where('status', RequestStatus::Draft)
            ->latest()
            ->get();

        $completed = NotarizationRequest::where('client_id', $userId)
            ->where('status', RequestStatus::Completed)
            // Every sealed PDF, not just the first — a request with three
            // documents has three finished files and the client paid for all of
            // them, so all of them have to be reachable from here.
            ->with('finalDocuments.sourceDocument')
            ->latest()
            ->get();

        return view('client.dashboard', compact('active', 'drafts', 'completed'));
    }
}
