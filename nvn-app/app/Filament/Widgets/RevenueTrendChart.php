<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Support\Analytics;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Successful request fees per day, in naira (not kobo — the stored minor unit
 * is divided out here so the axis reads as money).
 */
class RevenueTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Revenue — last 30 days (₦)';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $payments = Payment::query()
            ->whereIn('type', ['request_fee', 'offsite_fee'])
            ->where('status', 'successful')
            ->where('currency', 'NGN')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(30)->startOfDay())
            ->get(['amount', 'completed_at']);

        $start  = now()->startOfDay()->subDays(29);
        $totals = $payments
            ->groupBy(fn (Payment $p) => Carbon::parse($p->completed_at)->format('Y-m-d'))
            ->map(fn ($group) => $group->sum('amount') / 100);

        $labels = [];
        $values = [];

        for ($i = 0; $i < 30; $i++) {
            $day      = $start->copy()->addDays($i);
            $labels[] = $day->format('j M');
            $values[] = round((float) $totals->get($day->format('Y-m-d'), 0), 2);
        }

        return [
            'datasets' => [[
                'label'           => 'Revenue (₦)',
                'data'            => $values,
                'borderColor'     => '#3d8a27',
                'backgroundColor' => 'rgba(84, 180, 53, 0.18)',
                'fill'            => true,
                'tension'         => 0.3,
                'pointRadius'     => 2,
            ]],
            'labels' => $labels,
        ];
    }

    public function getDescription(): ?string
    {
        $total = Payment::query()
            ->whereIn('type', ['request_fee', 'offsite_fee'])
            ->where('status', 'successful')
            ->where('currency', 'NGN')
            ->where('completed_at', '>=', now()->subDays(30)->startOfDay())
            ->sum('amount');

        return 'Total collected in the period: ' . Analytics::money((int) $total);
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales'  => ['y' => ['beginAtZero' => true]],
        ];
    }
}
