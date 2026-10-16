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

    protected static ?string $heading = 'Leads trend';

    protected static ?string $description = 'Enquiries received and converted per day';

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
                    'borderColor' => '#d7141a',
                    'backgroundColor' => 'rgba(215, 20, 26, 0.12)',
                    'fill' => true,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Converted',
                    'data' => $b,
                    'borderColor' => '#d4a62a',
                    'backgroundColor' => 'rgba(212, 166, 42, 0.10)',
                    'fill' => true,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]]],
            'interaction' => ['intersect' => false, 'mode' => 'index'],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => 'rgba(127,127,127,0.12)']],
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 8]],
            ],
        ];
    }
}
