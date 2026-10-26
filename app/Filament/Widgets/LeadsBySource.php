<?php

namespace App\Filament\Widgets;

use App\Models\Lead;
use Filament\Widgets\Widget;

/** Where enquiries come from, as a ranked list of bars (easier to compare than a donut). */
class LeadsBySource extends Widget
{
    public static function canView(): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    protected static string $view = 'filament.widgets.leads-by-source';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 5];

    public string $filter = '30';

    public const FILTERS = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    protected function getViewData(): array
    {
        $days = array_key_exists($this->filter, self::FILTERS) ? (int) $this->filter : 30;

        $rows = \App\Support\Access::scopeOwn(\App\Models\Lead::query(), 'lead')
            ->where('leads.created_at', '>=', now()->subDays($days))
            ->join('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->selectRaw('lead_sources.name as source, count(*) as total')
            ->groupBy('lead_sources.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $max = max(1, (int) $rows->max('total'));
        $sum = max(1, (int) $rows->sum('total'));

        return [
            'filters' => self::FILTERS,
            'total' => (int) $rows->sum('total'),
            'rows' => $rows->map(fn ($r) => [
                'source' => $r->source,
                'total' => (int) $r->total,
                'width' => round($r->total / $max * 100, 1),
                'share' => round($r->total / $sum * 100),
            ]),
        ];
    }
}
