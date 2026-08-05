<?php

namespace App\Http\Controllers\Notary;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\PayoutService;
use App\Support\Analytics;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NotaryDashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();
        $profile = $user->notaryProfile;

        // The desk, not the profile: an admin also works jobs that are still
        // recorded against the notary who booked them. See scopeOnDeskOf().
        //
        // For the admin the queues go wider still — they may take over any
        // request on the platform, so showing only what has already been handed
        // to them would hide the ones actually waiting on a decision.
        $desk = fn () => $user->isAdmin()
            ? NotarizationRequest::query()
            : NotarizationRequest::onDeskOf($user);

        $pendingRequests = $desk()
            ->where('status', RequestStatus::Paid)
            ->with('client', 'service', 'session', 'notary.user')
            // Longest wait first — the only order that matters on a queue with
            // a clock running against it.
            ->oldest('paid_at')
            ->take(5)
            ->get();

        $activeSessions = $desk()
            ->whereIn('status', RequestStatus::active())
            ->where('status', '!=', RequestStatus::Paid->value)
            ->with('client', 'service', 'session', 'notary.user')
            ->latest('accepted_at')
            ->take(5)
            ->get();

        // Completed stays on the personal desk even for the admin: it feeds the
        // "you have completed N" line, not a platform-wide total.
        $completedCount = NotarizationRequest::onDeskOf($user)
            ->where('status', RequestStatus::Completed)
            ->count();

        return view('notary.dashboard', [
            'user'            => $user,
            'profile'         => $profile,
            'pendingRequests' => $pendingRequests,
            'activeSessions'  => $activeSessions,
            'completedCount'  => $completedCount,
            // Admins reach this screen as the platform's own notary. Their
            // onboarding and profile pages live in the Filament panel instead.
            'isAdminDesk'     => $user->isAdmin(),
        ] + $this->analytics($user, (int) ($profile?->commission_rate ?? 50)));
    }

    /**
     * Everything the charts on the dashboard need.
     *
     * Kept to three queries and grouped in PHP by App\Support\Analytics, so the
     * same code runs on SQLite here and on MySQL once this moves to cPanel.
     */
    private function analytics(User $user, int $commissionRate): array
    {
        $empty = [
            'stats'     => ['received' => 0, 'completed' => 0, 'open' => 0, 'acceptRate' => 0],
            'daily'     => Analytics::dailySeries(collect(), 30),
            'weekday'   => Analytics::dayOfWeekSeries(collect()),
            'hours'     => Analytics::hourBlockSeries(collect()),
            'statusMix' => [],
            'earnings'  => ['gross' => 0, 'share' => 0, 'thisMonth' => 0, 'covered' => 0,
                            'owed' => 0, 'paidOut' => 0, 'rate' => $commissionRate],
        ];

        if (! $user->notaryProfile && ! $user->isAdmin()) {
            return $empty;
        }

        $requests = NotarizationRequest::onDeskOf($user)
            ->with('notary:id,commission_rate')
            ->get(['id', 'notary_id', 'status', 'submitted_at', 'accepted_at', 'completed_at', 'created_at']);

        if ($requests->isEmpty()) {
            return $empty;
        }

        $completed = $requests->where('status', RequestStatus::Completed);

        // A declined request is reassigned away and no longer carries this
        // notary's id, so "responded" is measured off accepted_at — the only
        // signal still attached to the row.
        $responded = $requests->whereNotNull('accepted_at')->count();

        $completedIds = $completed->pluck('id');

        $payments = $completedIds->isEmpty() ? collect() : Payment::query()
            ->where('type', 'request_fee')
            ->where('status', 'successful')
            ->where('currency', 'NGN')
            ->whereIn('request_id', $completedIds)
            ->get(['request_id', 'amount', 'completed_at']);

        $earnings = $this->splitEarnings($payments, $requests, $user, $commissionRate)
            + $this->payoutPosition($user);

        return [
            'stats' => [
                'received'   => $requests->count(),
                'completed'  => $completed->count(),
                'open'       => $requests->whereIn('status', RequestStatus::active())->count(),
                'acceptRate' => Analytics::percent($responded, $requests->count()),
            ],
            'daily'     => Analytics::dailySeries(Analytics::dates($requests, 'created_at'), 30),
            'weekday'   => Analytics::dayOfWeekSeries(Analytics::dates($requests, 'created_at')),
            'hours'     => Analytics::hourBlockSeries(Analytics::dates($requests, 'created_at')),
            'statusMix' => $requests->countBy(fn ($r) => $r->status->label())->sortDesc()->all(),
            'earnings'  => $earnings,
        ];
    }

    /**
     * Money already sent, and money still waiting to be sent.
     *
     * This is a different question from "what have I earned": earnings count a
     * job the moment it completes, while a payout only exists once the platform
     * runs one. The gap between the two lines is what the notary is chasing.
     *
     * The platform's own profile is excluded — it never transfers to itself, so
     * a payout figure there would be meaningless.
     */
    private function payoutPosition(User $user): array
    {
        $profile = $user->notaryProfile;

        if (! $profile || $profile->is_system_native) {
            return ['owed' => 0, 'paidOut' => 0];
        }

        return [
            'owed'    => app(PayoutService::class)->owed($profile),
            'paidOut' => (int) Payout::where('notary_profile_id', $profile->id)
                ->where('status', 'paid')
                ->sum('amount'),
        ];
    }

    /**
     * Split each completed job's fee between the notary of record and the platform.
     *
     * The notary the client selected always keeps their agreed share, even when
     * the platform did the work on their behalf — covering a job does not move
     * the money, only the keystrokes. So on a covered job the platform's own
     * take is its commission and nothing more, and it is counted at the
     * PARTNER's rate, not the platform profile's.
     *
     * For a partner this loop only ever sees their own jobs, so it reduces to
     * the old "gross minus commission".
     */
    private function splitEarnings($payments, $requests, User $user, int $commissionRate): array
    {
        $myProfileId = $user->notaryProfile?->id;
        $byId        = $requests->keyBy('id');
        $monthStart  = now()->startOfMonth();

        $gross = $share = $thisMonth = $covered = 0;

        foreach ($payments as $payment) {
            $profile = $byId->get($payment->request_id)?->notary;
            $rate    = (int) ($profile?->commission_rate ?? $commissionRate);
            $mine    = $profile !== null && $profile->id === $myProfileId;

            $earned = $mine
                ? (int) round($payment->amount * (100 - $rate) / 100)  // notary of record
                : (int) round($payment->amount * $rate / 100);         // covered for a partner

            $gross += $payment->amount;
            $share += $earned;

            if (! $mine) {
                $covered += $earned;
            }

            if ($payment->completed_at !== null && $payment->completed_at >= $monthStart) {
                $thisMonth += $earned;
            }
        }

        return [
            'gross'     => $gross,
            'share'     => $share,
            'thisMonth' => $thisMonth,
            'covered'   => $covered,
            'rate'      => $commissionRate,
        ];
    }
}
