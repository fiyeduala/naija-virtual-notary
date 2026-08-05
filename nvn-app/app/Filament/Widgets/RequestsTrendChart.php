<?php

namespace App\Filament\Widgets;

use App\Models\NotarizationRequest;
use App\Support\Analytics;
use Filament\Widgets\ChartWidget;

/** Requests submitted per day over the trailing month. */
class RequestsTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Requests received — last 30 days';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $rows = NotarizationRequest::query()
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', now()->subDays(30)->startOfDay())
            ->get(['submitted_at']);

        $series = Analytics::dailySeries(Analytics::dates($rows, 'submitted_at'), 30);

        return [
            'datasets' => [[
                'label'           => 'Requests',
                'data'            => $series['values'],
                'backgroundColor' => 'rgba(84, 180, 53, 0.75)',
                'borderColor'     => '#3d8a27',
                'borderRadius'    => 4,
            ]],
            'labels' => $series['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales'  => [
                // Request counts are whole numbers — Chart.js otherwise labels
                // the axis 0, 0.5, 1 on a quiet week.
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
