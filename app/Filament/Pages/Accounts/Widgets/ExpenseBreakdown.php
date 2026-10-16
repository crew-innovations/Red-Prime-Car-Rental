<?php

namespace App\Filament\Pages\Accounts\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;

class ExpenseBreakdown extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Where the money goes';

    protected static ?string $description = 'Expenses by category';

    protected static ?string $maxHeight = '260px';

    public ?string $filter = 'year';

    protected function getFilters(): ?array
    {
        return ['month' => 'This month', 'year' => 'This year', 'all' => 'All time'];
    }

    protected function getData(): array
    {
        $rows = Transaction::query()
            ->expense()
            ->where('status', '<>', 'refunded')
            ->whereNotIn('category', Transaction::NON_COST_CATEGORIES)
            ->when($this->filter === 'month', fn ($q) => $q->where('date', '>=', now()->startOfMonth()))
            ->when($this->filter === 'year', fn ($q) => $q->where('date', '>=', now()->startOfYear()))
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        return [
            'datasets' => [[
                'label' => 'Expenses (AED)',
                'data' => $rows->map(fn ($r) => round((float) $r->total, 2))->all(),
                'backgroundColor' => ['#d7141a', '#d4a62a', '#3b82f6', '#22c55e', '#a855f7', '#f97316', '#14b8a6', '#ec4899', '#71717a', '#eab308', '#0ea5e9', '#84cc16', '#64748b'],
                'borderWidth' => 0,
            ]],
            'labels' => $rows->map(fn ($r) => Transaction::categories('expense')[$r->category] ?? $r->category)->all(),
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
