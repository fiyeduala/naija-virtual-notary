<?php

namespace App\Filament\Widgets;

use App\Models\NotarizationRequest;
use App\Support\Analytics;
use Filament\Widgets\ChartWidget;

/**
 * Which weekday brings in the most work. Drives staffing and the notary
 * availability nudge — if Monday is 3x Friday, that is where cover is needed.
 */
class BusiestDayChart extends ChartWidget
{
    protected static ?string $heading = 'Busiest day of the week';

    protected static ?int $sort = 5;

    protected static ?string $maxHeight = '260px';

    public function getDescription(): ?string
    {
        $series = $this->series();

        return $series['busiest']
            ? $series['busiest'] . ' takes the most requests — all time.'
            : 'No requests submitted yet.';
    }

    private function series(): array
    {
        $rows = NotarizationRequest::query()
            ->whereNotNull('submitted_at')
            ->get(['submitted_at']);

        return Analytics::dayOfWeekSeries(Analytics::dates($rows, 'submitted_at'));
    }

    protected function getData(): array
    {
        $series = $this->series();
        $max    = max($series['values'] ?: [0]);

        // Highlight the peak bar rather than making the reader compare heights.
        $colors = array_map(
            fn (int $v) => $v > 0 && $v === $max ? '#54B435' : 'rgba(84, 180, 53, 0.35)',
            $series['values'],
        );

        return [
            'datasets' => [[
                'label'           => 'Requests',
                'data'            => $series['values'],
                'backgroundColor' => $colors,
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
            'scales'  => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
