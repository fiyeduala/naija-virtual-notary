<?php

namespace App\Filament\Widgets;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use Filament\Widgets\ChartWidget;

/** Who is actually carrying the volume. */
class TopNotariesChart extends ChartWidget
{
    protected static ?string $heading = 'Completed notarizations by notary';

    protected static ?int $sort = 7;

    protected static ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $counts = NotarizationRequest::query()
            ->where('status', RequestStatus::Completed->value)
            ->whereNotNull('notary_id')
            ->with('notary.user')
            ->get()
            ->groupBy('notary_id')
            ->map(fn ($group) => [
                'name'  => $group->first()->notary?->user?->full_name ?? 'Unknown',
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->take(8)
            ->values();

        return [
            'datasets' => [[
                'label'           => 'Completed',
                'data'            => $counts->pluck('count')->all(),
                'backgroundColor' => 'rgba(84, 180, 53, 0.75)',
                'borderRadius'    => 4,
            ]],
            'labels' => $counts->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            // Horizontal — notary names are far too long for an x-axis.
            'indexAxis' => 'y',
            'plugins'   => ['legend' => ['display' => false]],
            'scales'    => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
