<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Enums\CarStatus;
use App\Enums\Readiness;
use App\Filament\Resources\CarResource;
use App\Models\Car;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCars extends ListRecords
{
    protected static string $resource = CarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add car'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return CarResource::getWidgets();
    }

    public function getTabs(): array
    {
        $count = fn (?CarStatus $status = null) => Car::query()
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->count();

        return [
            'all' => Tab::make('All'),
            'available' => Tab::make('Available')
                ->badge(Car::where('status', CarStatus::Available)->where('readiness', Readiness::Ready)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CarStatus::Available)->where('readiness', Readiness::Ready)),
            'attention' => Tab::make('Needs cleaning / inspection')
                ->badge(fn () => Car::where('readiness', '!=', Readiness::Ready)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('readiness', '!=', Readiness::Ready)),
            'rented' => Tab::make('Rented / Reserved')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [CarStatus::Rented, CarStatus::Reserved])),
            'maintenance' => Tab::make('Maintenance')
                ->badge(fn () => $count(CarStatus::Maintenance) ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CarStatus::Maintenance)),
            'documents' => Tab::make('Documents due')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge(fn () => Car::documentsDueSoon()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->documentsDueSoon()),
            'hidden' => Tab::make('Hidden from website')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', false)),
        ];
    }
}
