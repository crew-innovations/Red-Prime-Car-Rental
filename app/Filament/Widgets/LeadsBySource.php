<?php

namespace App\Filament\Widgets;

use App\Models\Lead;
use Filament\Widgets\ChartWidget;

class LeadsBySource extends ChartWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Leads by source';

    protected static ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getData(): array
    {
        $rows = Lead::query()
            ->where('leads.created_at', '>=', now()->subDays((int) $this->filter))
            ->join('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->selectRaw('lead_sources.name as source, count(*) as total')
            ->groupBy('lead_sources.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return [
            'datasets' => [[
                'label' => 'Leads',
                'data' => $rows->pluck('total')->all(),
                'backgroundColor' => ['#d7141a', '#d4a62a', '#3b82f6', '#22c55e', '#a855f7', '#f97316', '#14b8a6', '#71717a'],
                'borderWidth' => 0,
            ]],
            'labels' => $rows->pluck('source')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'right']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
