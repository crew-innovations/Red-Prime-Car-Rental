<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;

class LeadsTrend extends ChartWidget
{
    public static function canView(): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 7];

    protected static ?string $heading = 'Leads trend';

    protected static ?string $description = 'Enquiries received and won, per day';

    protected static ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['14' => 'Last 14 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getData(): array
    {
        $days = (int) $this->filter;
        $from = today()->subDays($days - 1);

        $received = Lead::where('created_at', '>=', $from)
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $converted = Lead::where('status', LeadStatus::Converted)
            ->where('converted_at', '>=', $from)
            ->selectRaw('date(converted_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = $a = $b = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i);
            $labels[] = $day->format('j M');
            $a[] = (int) ($received[$day->toDateString()] ?? 0);
            $b[] = (int) ($converted[$day->toDateString()] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Received',
                    'data' => $a,
                    'backgroundColor' => '#d7141a',
                    'borderRadius' => 4,
                    'borderWidth' => 0,
                    'borderSkipped' => 'start',
                    'maxBarThickness' => 12,
                    'categoryPercentage' => 0.8,
                    'barPercentage' => 0.9,
                ],
                [
                    'label' => 'Converted',
                    'data' => $b,
                    'backgroundColor' => '#b8891a',
                    'borderRadius' => 4,
                    'borderWidth' => 0,
                    'borderSkipped' => 'start',
                    'maxBarThickness' => 12,
                    'categoryPercentage' => 0.8,
                    'barPercentage' => 0.9,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'top', 'align' => 'end', 'labels' => ['usePointStyle' => true, 'pointStyle' => 'rectRounded', 'boxWidth' => 8, 'boxHeight' => 8]]],
            'interaction' => ['intersect' => false, 'mode' => 'index'],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => 'rgba(127,127,127,0.12)'], 'border' => ['display' => false]],
                'x' => ['grid' => ['display' => false], 'border' => ['display' => false], 'ticks' => ['maxTicksLimit' => 7, 'maxRotation' => 0, 'autoSkipPadding' => 16]],
            ],
        ];
    }
}
