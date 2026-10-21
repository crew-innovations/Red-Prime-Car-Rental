<?php

namespace App\Filament\Pages\Accounts\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;

class RevenueByService extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Revenue by service';

    protected static ?string $description = 'Cars, yachts and yacht trips';

    protected static ?string $maxHeight = '260px';

    public ?string $filter = 'year';

    protected function getFilters(): ?array
    {
        return ['month' => 'This month', 'year' => 'This year', 'all' => 'All time'];
    }

    protected function getData(): array
    {
        $labels = ['car' => 'Cars', 'yacht' => 'Yacht charters (old)', 'trip' => 'Yacht trips', 'package' => 'Trips & packages', '' => 'Other'];

        $rows = Transaction::query()
            ->when($this->filter === 'month', fn ($q) => $q->where('date', '>=', now()->startOfMonth()))
            ->when($this->filter === 'year', fn ($q) => $q->where('date', '>=', now()->startOfYear()))
            ->selectRaw("COALESCE(related_type, '') as service, ".Transaction::receivedSql().' as total')
            ->groupBy('service')
            ->toBase()
            ->pluck('total', 'service');

        $data = collect($labels)->map(fn ($label, $key) => round((float) ($rows[$key] ?? 0), 2));

        return [
            'datasets' => [[
                'label' => 'Revenue (AED)',
                'data' => $data->values()->all(),
                'backgroundColor' => ['#d7141a', '#3b82f6', '#d4a62a', '#71717a'],
                'borderWidth' => 0,
            ]],
            'labels' => array_values($labels),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '62%',
            'plugins' => ['legend' => ['position' => 'right', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]]],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
