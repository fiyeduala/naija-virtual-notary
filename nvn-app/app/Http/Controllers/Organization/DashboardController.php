<?php

namespace App\Http\Controllers\Organization;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\Organization;
use App\Services\OrganizationPayoutService;
use App\Support\OrganizationPricing;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * What a partner body can see of its own arrangement.
 *
 * The boundary is the whole point of this screen: a body is a referrer, not a
 * party to the notarization. It sees its link, its rates, how much work it has
 * sent and what state that work is in — by reference. It never sees a client's
 * name, contact details or documents, because it has no standing to and the
 * client never agreed to it.
 *
 * The earnings panel exists only under the commission arrangement. A
 * price_only body is charged its own rate and earns nothing, so a zeroed
 * panel would say nothing true and would only invite the question.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly OrganizationPayoutService $payouts,
        private readonly OrganizationPricing $pricing,
    ) {
    }

    public function index(): View
    {
        /** @var Organization $organization */
        $organization = Auth::guard('organization')->user();
        $organization->load('prices');

        $requests = NotarizationRequest::query()
            ->where('organization_id', $organization->id)
            // Only the columns this screen is allowed to show. Selecting them
            // explicitly rather than hydrating the whole row is what keeps a
            // client's details out of reach of a careless view edit later.
            ->select([
                'id', 'reference', 'status', 'service_id', 'currency',
                'unit_fee_minor', 'created_at', 'completed_at',
            ])
            ->with('service:id,service_type,price_ngn,price_usd')
            ->latest()
            ->paginate(15);

        $all = NotarizationRequest::where('organization_id', $organization->id);

        $counts = [
            'referred'   => (clone $all)->count(),
            'completed'  => (clone $all)->where('status', RequestStatus::Completed->value)->count(),
            'inProgress' => (clone $all)->whereIn('status', array_map(
                fn (RequestStatus $status) => $status->value,
                RequestStatus::active(),
            ))->count(),
            'people'     => $organization->referredUsers()->count(),
        ];

        // Gross is what this body's referrals actually paid, which it is
        // entitled to know whatever its arrangement — it is the money the body
        // itself negotiated the rate for.
        $gross = (int) \App\Models\Payment::query()
            ->where('type', 'request_fee')
            ->where('status', 'successful')
            ->whereIn('request_id', (clone $all)->select('id'))
            ->sum('amount');

        $earnings = null;

        if ($organization->earnsCommission()) {
            $earnings = [
                'owed'   => $this->payouts->owed($organization),
                'paid'   => $this->payouts->paidOut($organization),
                'payouts' => $organization->payouts()->latest()->take(10)->get(),
            ];
        }

        // The body's own rates, beside the categories they apply to, so it can
        // check what its applicants are being quoted without ringing anyone.
        $notary = \App\Models\NotaryProfile::systemNative()->first();

        $services = ($notary
                ? $notary->services()->where('active', true)->orderBy('service_type')->get()
                : collect())
            ->map(fn ($service) => [
                'name'  => $service->service_type,
                'price' => $this->pricing->displayUnitPrice($organization, $service, 'NGN'),
            ]);

        return view('organizations.dashboard', [
            'organization' => $organization,
            'requests'     => $requests,
            'counts'       => $counts,
            'gross'        => $gross,
            'earnings'     => $earnings,
            'services'     => $services,
        ]);
    }
}
