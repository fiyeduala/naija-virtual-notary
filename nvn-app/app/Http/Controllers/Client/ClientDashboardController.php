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
            ->with('notary.user', 'session')
            ->latest()
            ->get();

        $drafts = NotarizationRequest::where('client_id', $userId)
            ->where('status', RequestStatus::Draft)
            ->latest()
            ->get();

        $completed = NotarizationRequest::where('client_id', $userId)
            ->where('status', RequestStatus::Completed)
            ->with('finalDocument')
            ->latest()
            ->get();

        return view('client.dashboard', compact('active', 'drafts', 'completed'));
    }
}
