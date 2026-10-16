<?php

namespace App\Filament\Resources\YachtResource\Pages;

use App\Enums\YachtStatus;
use App\Filament\Resources\YachtResource;
use App\Models\Yacht;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListYachts extends ListRecords
{
    protected static string $resource = YachtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add yacht'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return YachtResource::getWidgets();
    }

    public function getTabs(): array
    {
        $count = fn (YachtStatus $status) => Yacht::where('status', $status)->count() ?: null;

        return [
            'all' => Tab::make('All'),
            'available' => Tab::make('Available')
                ->badge(fn () => $count(YachtStatus::Available))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', YachtStatus::Available)),
            'booked' => Tab::make('Booked')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', YachtStatus::Booked)),
            'maintenance' => Tab::make('Maintenance')
                ->badge(fn () => $count(YachtStatus::Maintenance))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', YachtStatus::Maintenance)),
            'documents' => Tab::make('Documents due')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge(fn () => Yacht::documentsDueSoon()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->documentsDueSoon()),
            'hidden' => Tab::make('Hidden from website')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('is_published', false)),
        ];
    }
}
