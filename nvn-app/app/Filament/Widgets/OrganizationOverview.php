<?php

namespace App\Filament\Widgets;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\Payment;
use App\Services\OrganizationPayoutService;
use App\Support\Analytics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How much of the business comes from partner bodies.
 *
 * Its own widget rather than four more tiles on PlatformOverview, which is the
 * platform's own health and already full. These four answer a different
 * question — whether the partnerships are worth the paperwork — and they are
 * read together or not at all.
 *
 * Hidden entirely until the first body exists, so an installation with no
 * partnerships is not asked to look at four zeros every morning.
 */
class OrganizationOverview extends BaseWidget
{
    protected static ?int $sort = 8;

    public static function canView(): bool
    {
        return Organization::exists();
    }

    protected function getStats(): array
    {
        $active = Organization::active()->count();
        $waiting = Organization::pending()->count();

        $ordersThisMonth = NotarizationRequest::fromOrganizations()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $completedThisMonth = NotarizationRequest::fromOrganizations()
            ->where('status', RequestStatus::Completed->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        // Gross, not the platform's share. The split does not change what came
        // in, and this tile answers "how much business did the bodies bring".
        $grossAllTime = Payment::query()
            ->where('type', 'request_fee')
            ->where('status', 'successful')
            ->whereIn('request_id', NotarizationRequest::query()
                ->whereNotNull('organization_id')
                ->select('id'))
            ->sum('amount');

        $grossThisMonth = Payment::query()
            ->where('type', 'request_fee')
            ->where('status', 'successful')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->whereIn('request_id', NotarizationRequest::query()
                ->whereNotNull('organization_id')
                ->select('id'))
            ->sum('amount');

        // Owed is asked of the service rather than summed here, so there is one
        // definition of what a body has earned. Only commission bodies are
        // asked: a body charged a rate only earns nothing by arrangement, and
        // including it would read as a debt that will never be settled.
        $payouts = app(OrganizationPayoutService::class);

        $owed = Organization::active()
            ->where('arrangement', Organization::COMMISSION)
            ->get()
            ->sum(fn (Organization $organization) => $payouts->owed($organization));

        $unsettled = (int) OrganizationPayout::outstanding()->sum('amount');

        return [
            Stat::make('Partner organizations', $active)
                ->description($waiting > 0
                    ? $waiting . ' ' . str('application')->plural($waiting) . ' waiting'
                    : 'No applications waiting')
                ->color($waiting > 0 ? 'warning' : 'gray'),
            Stat::make('Their orders this month', $ordersThisMonth)
                ->description($completedThisMonth . ' completed')
                ->color($ordersThisMonth > 0 ? 'success' : 'gray'),
            Stat::make('Gross from organizations', Analytics::money((int) $grossThisMonth))
                ->description('All time: ' . Analytics::money((int) $grossAllTime))
                ->color($grossThisMonth > 0 ? 'success' : 'gray'),
            Stat::make('Commission owed', Analytics::money((int) $owed))
                ->description($unsettled > 0
                    ? Analytics::money($unsettled) . ' already on an unsettled payout'
                    : 'Nothing generated and unsettled')
                ->color($owed > 0 || $unsettled > 0 ? 'warning' : 'gray'),
        ];
    }
}
