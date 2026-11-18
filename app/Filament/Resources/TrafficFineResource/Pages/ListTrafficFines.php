<?php

namespace App\Filament\Resources\TrafficFineResource\Pages;

use App\Enums\FineStatus;
use App\Filament\Resources\TrafficFineResource;
use App\Models\TrafficFine;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTrafficFines extends ListRecords
{
    protected static string $resource = TrafficFineResource::class;

    protected ?string $subheading = 'Fines are passed on to the customer who had the car: not revenue, and not a cost once the customer pays.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')->label('Import statement')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->url(fn () => \App\Filament\Pages\StatementImport::getUrl(['kind' => 'fines'])),
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add fine'),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (array $s) => TrafficFine::whereIn('status', $s)->count() ?: null;

        return [
            'review' => Tab::make('Needs review')->badge($count(['new']))->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FineStatus::New)),
            'matched' => Tab::make('To charge')->badge($count(['matched']))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FineStatus::Matched)),
            'charged' => Tab::make('Charged, not paid by the customer')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FineStatus::Charged)),
            'unpaid' => Tab::make('Not paid to authority')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('paid_at')->whereNotIn('status', [FineStatus::Disputed->value, FineStatus::RechargedSupplier->value])),
            'paid' => Tab::make('Paid to authority')->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('paid_at')),
            'closed' => Tab::make('Disputed / waived / company')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [FineStatus::Disputed, FineStatus::Waived, FineStatus::CompanyCost, FineStatus::RechargedSupplier])),
            'all' => Tab::make('All'),
        ];
    }
}
