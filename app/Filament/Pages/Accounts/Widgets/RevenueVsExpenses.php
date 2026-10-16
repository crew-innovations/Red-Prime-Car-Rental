<?php

namespace App\Filament\Pages\Accounts\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;

class RevenueVsExpenses extends ChartWidget
{
    protected static ?int $sort = 1;

    protected static ?string $heading = 'Revenue vs expenses';

    protected static ?string $description = 'Last 12 months, with net profit';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $from = now()->subMonthsNoOverflow(11)->startOfMonth();

        $rows = Transaction::query()
            ->where('date', '>=', $from)
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, ".Transaction::receivedSql().' as received, '.Transaction::expensesSql().' as expenses')
            ->groupBy('ym')
            ->toBase()
            ->get()
            ->keyBy('ym');

        $labels = $revenue = $expenses = $net = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $from->copy()->addMonthsNoOverflow($i);
            $row = $rows[$month->format('Y-m')] ?? null;
            $r = round((float) ($row->received ?? 0), 2);
            $e = round((float) ($row->expenses ?? 0), 2);
            $labels[] = $month->format('M y');
            $revenue[] = $r;
            $expenses[] = $e;
            $net[] = round($r - $e, 2);
        }

        return [
            'datasets' => [
                ['type' => 'line', 'label' => 'Net profit', 'data' => $net, 'borderColor' => '#d4a62a', 'backgroundColor' => '#d4a62a', 'tension' => 0.35, 'borderWidth' => 2, 'pointRadius' => 3, 'order' => 0],
                ['label' => 'Revenue', 'data' => $revenue, 'backgroundColor' => 'rgba(34, 197, 94, 0.75)', 'borderRadius' => 6, 'order' => 1],
                ['label' => 'Expenses', 'data' => $expenses, 'backgroundColor' => 'rgba(215, 20, 26, 0.75)', 'borderRadius' => 6, 'order' => 1],
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
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]]],
            'interaction' => ['intersect' => false, 'mode' => 'index'],
            'scales' => [
                'y' => ['beginAtZero' => true, 'grid' => ['color' => 'rgba(127,127,127,0.12)']],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
