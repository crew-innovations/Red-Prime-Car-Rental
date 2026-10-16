<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Enums\CarStatus;
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
                ->badge($count(CarStatus::Available))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', CarStatus::Available)),
            'rented' => Tab::make('Rented / Reserved')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('status', [CarStatus::Rented, CarStatus::Reserved])),
            'maintenance' => Tab::make('Maintenance')
                ->badge(fn () => $count(CarStatus::Maintenance) ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', CarStatus::Maintenance)),
            'documents' => Tab::make('Documents due')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge(fn () => Car::documentsDueSoon()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->documentsDueSoon()),
            'hidden' => Tab::make('Hidden from website')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('is_published', false)),
        ];
    }
}
