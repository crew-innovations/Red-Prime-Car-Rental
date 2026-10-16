<?php

namespace App\Filament\Widgets;

use App\Enums\CarStatus;
use App\Enums\YachtStatus;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\YachtResource;
use App\Models\Car;
use App\Models\Yacht;
use Filament\Widgets\Widget;

/** Cars and yachts by status as bars, plus documents needing attention. */
class FleetStatus extends Widget
{
    public static function canView(): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    protected static string $view = 'filament.widgets.fleet-status';

    protected static ?int $sort = 4;

    /** Bar colours per status (CSS rgb triplets). */
    protected const COLORS = [
        'success' => 'var(--success-500)',
        'warning' => 'var(--warning-500)',
        'info' => 'var(--info-500)',
        'danger' => 'var(--danger-500)',
        'gray' => 'var(--gray-400)',
    ];

    protected function getViewData(): array
    {
        $fleets = [
            ['label' => 'Cars', 'url' => CarResource::getUrl('index'), 'counts' => Car::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'), 'cases' => CarStatus::cases()],
            ['label' => 'Yachts', 'url' => YachtResource::getUrl('index'), 'counts' => Yacht::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'), 'cases' => YachtStatus::cases()],
        ];

        $groups = collect($fleets)->map(function (array $fleet) {
            $total = (int) $fleet['counts']->sum();

            return [
                'label' => $fleet['label'],
                'url' => $fleet['url'],
                'total' => $total,
                'segments' => collect($fleet['cases'])->map(fn ($case) => [
                    'label' => $case->getLabel(),
                    'count' => (int) ($fleet['counts'][$case->value] ?? 0),
                    'pct' => $total ? round(($fleet['counts'][$case->value] ?? 0) / $total * 100, 1) : 0,
                    'color' => self::COLORS[$case->getColor()] ?? self::COLORS['gray'],
                ])->all(),
            ];
        });

        $attention = collect()
            ->merge(Car::documentsDueSoon()->get()->map(fn (Car $car) => [
                'name' => $car->name,
                'alerts' => implode(' · ', $car->documentAlerts()),
                'url' => CarResource::getUrl('edit', ['record' => $car, 'tab' => '-documents-tab']),
            ]))
            ->merge(Yacht::documentsDueSoon()->get()->map(fn (Yacht $yacht) => [
                'name' => $yacht->name,
                'alerts' => implode(' · ', $yacht->documentAlerts()),
                'url' => YachtResource::getUrl('edit', ['record' => $yacht, 'tab' => '-documents-tab']),
            ]))
            ->take(5);

        return ['groups' => $groups, 'attention' => $attention];
    }
}
