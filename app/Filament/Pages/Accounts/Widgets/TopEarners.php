<?php

namespace App\Filament\Pages\Accounts\Widgets;

use App\Filament\Resources\CarResource;
use App\Filament\Resources\TripResource;
use App\Filament\Resources\YachtResource;
use App\Models\Car;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\Yacht;
use Filament\Widgets\Widget;

/** Cars, yachts and trips ranked by profit this year. */
class TopEarners extends Widget
{
    protected static string $view = 'filament.widgets.top-earners';

    protected static ?int $sort = 4;

    protected function getViewData(): array
    {
        $rows = Transaction::query()
            ->whereNotNull('related_type')
            ->where('date', '>=', now()->startOfYear())
            ->selectRaw('related_type, related_id, '.Transaction::receivedSql().' as received, '.Transaction::expensesSql().' as expenses')
            ->groupBy('related_type', 'related_id')
            ->toBase()
            ->get()
            ->map(fn ($r) => (object) [
                'type' => $r->related_type,
                'id' => (int) $r->related_id,
                'received' => (float) $r->received,
                'expenses' => (float) $r->expenses,
                'net' => (float) $r->received - (float) $r->expenses,
            ])
            ->sortByDesc('net')
            ->take(6)
            ->values();

        $models = [
            'car' => [Car::class, CarResource::class, 'Car'],
            'yacht' => [Yacht::class, YachtResource::class, 'Yacht'],
            'trip' => [Trip::class, TripResource::class, 'Trip'],
        ];

        $names = $rows->groupBy('type')->map(fn ($group, $type) => isset($models[$type])
            ? $models[$type][0]::withTrashed()->whereIn('id', $group->pluck('id'))->pluck('name', 'id')
            : collect());

        $max = max(1, (float) $rows->max('received'));

        $items = $rows->map(fn ($r) => [
            'name' => $names[$r->type][$r->id] ?? 'Deleted item',
            'kind' => $models[$r->type][2] ?? ucfirst($r->type),
            'url' => isset($models[$r->type]) ? $models[$r->type][1]::getUrl('edit', ['record' => $r->id]) : null,
            'received' => $r->received,
            'expenses' => $r->expenses,
            'net' => $r->net,
            'pct' => round($r->received / $max * 100),
        ]);

        return ['items' => $items];
    }
}
