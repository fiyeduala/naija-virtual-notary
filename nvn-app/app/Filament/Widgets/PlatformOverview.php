<?php

namespace App\Filament\Widgets;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Support\Analytics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = now()->startOfDay();

        $sessionsToday = NotarizationRequest::whereHas('session', fn ($q) =>
            $q->whereBetween('scheduled_start_at', [$today, now()->endOfDay()])
        )->count();

        $pendingNotaries = NotaryProfile::where('verification_status', 'pending')
            ->whereNotNull('onboarding_fee_paid_at')
            ->count();

        // Offsite excluded: a paid offsite job is not waiting on anybody. The
        // notary who paid for it is the one who will seal it, and counting it
        // here would show the desk work that does not exist.
        $awaitingNotary = NotarizationRequest::marketplace()
            ->where('status', RequestStatus::Paid->value)
            ->count();

        $hardCopyQueue = NotarizationRequest::where('status', RequestStatus::Completed->value)
            ->where('hard_copy_requested', true)
            ->count();

        $revenueToday = Payment::whereIn('type', ['request_fee', 'offsite_fee'])
            ->where('status', 'successful')
            ->where('currency', 'NGN')
            ->whereDate('completed_at', today())
            ->sum('amount');

        $revenueMonth = Payment::whereIn('type', ['request_fee', 'offsite_fee'])
            ->where('status', 'successful')
            ->where('currency', 'NGN')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->sum('amount');

        $openQuotes = QuoteRequest::where('status', 'new')->count();

        return [
            Stat::make('Sessions today', $sessionsToday),
            Stat::make('Notaries awaiting review', $pendingNotaries)
                ->color($pendingNotaries > 0 ? 'warning' : 'gray'),
            Stat::make('Requests awaiting a notary', $awaitingNotary)
                ->color($awaitingNotary > 0 ? 'warning' : 'gray'),
            Stat::make('Hard-copy fulfillment queue', $hardCopyQueue)
                ->color($hardCopyQueue > 0 ? 'warning' : 'gray'),
            // Amounts are stored in kobo. Printing the raw minor unit made a
            // ₦25,000 sale read as "2,500,000" on the dashboard.
            Stat::make('Revenue today', Analytics::money((int) $revenueToday))
                ->description('This month: ' . Analytics::money((int) $revenueMonth))
                ->color($revenueToday > 0 ? 'success' : 'gray'),
            Stat::make('Open quote requests', $openQuotes),
        ];
    }
}
