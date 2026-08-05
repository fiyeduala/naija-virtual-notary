<?php

namespace App\Filament\Widgets;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use Filament\Widgets\ChartWidget;

/** Where every request in the system currently sits. */
class RequestStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Requests by status';

    protected static ?int $sort = 4;

    protected static ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $counts = NotarizationRequest::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $labels = [];
        $values = [];
        $colors = [];

        // Palette runs cool (early stages) to green (done) to red (lost), so the
        // shape of the doughnut reads as progress at a glance.
        $palette = [
            'draft'           => '#cbd5e1',
            'submitted'       => '#94a3b8',
            'paid'            => '#f5a524',
            'accepted'        => '#7cc4f0',
            'scheduled'       => '#4f9fd8',
            'in_verification' => '#9b8afb',
            'notarizing'      => '#7c6ef0',
            'completed'       => '#54B435',
            'cancelled'       => '#e0684f',
            'refunded'        => '#b45309',
        ];

        foreach (RequestStatus::cases() as $case) {
            $count = (int) $counts->get($case->value, 0);

            // Empty statuses would render as invisible slices with dead legend
            // entries — drop them.
            if ($count === 0) {
                continue;
            }

            $labels[] = $case->label();
            $values[] = $count;
            $colors[] = $palette[$case->value] ?? '#94a3b8';
        }

        return [
            'datasets' => [[
                'label'           => 'Requests',
                'data'            => $values,
                'backgroundColor' => $colors,
                'borderWidth'     => 0,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins'     => ['legend' => ['position' => 'right']],
            'maintainAspectRatio' => false,
        ];
    }
}
