<?php

namespace App\Filament\Resources\YachtResource\Pages;

use App\Enums\YachtStatus;
use App\Enums\Readiness;
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
            \App\Support\Exports\TableExport::action(),
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
                ->badge(fn () => Yacht::where('status', YachtStatus::Available)->where('readiness', Readiness::Ready)->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', YachtStatus::Available)->where('readiness', Readiness::Ready)),
            'attention' => Tab::make('Needs cleaning / inspection')
                ->badge(fn () => Yacht::where('readiness', '!=', Readiness::Ready)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('readiness', '!=', Readiness::Ready)),
            'booked' => Tab::make('Booked')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', YachtStatus::Booked)),
            'maintenance' => Tab::make('Maintenance')
                ->badge(fn () => $count(YachtStatus::Maintenance))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', YachtStatus::Maintenance)),
            'documents' => Tab::make('Documents due')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge(fn () => Yacht::documentsDueSoon()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->documentsDueSoon()),
            'hidden' => Tab::make('Hidden from website')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', false)),
        ];
    }
}
