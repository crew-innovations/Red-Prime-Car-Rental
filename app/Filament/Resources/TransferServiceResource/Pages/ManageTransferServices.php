<?php

namespace App\Filament\Resources\TransferServiceResource\Pages;

use App\Filament\Resources\TransferServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferServices extends ManageRecords
{
    protected static string $resource = TransferServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 6 Nov (limousine L1, H-46): Admin decides to load the market-based starter list; it only adds what is missing.
            Actions\Action::make('starter')->label('Load starter limousine list')->icon('heroicon-o-sparkles')->color('gray')
                ->visible(fn (): bool => \App\Support\Access::allows('manage-pricing'))
                ->requiresConfirmation()
                ->modalHeading('Load the starter limousine list?')
                ->modalDescription('Adds 6 services, 6 vehicle classes (business sedan to Rolls-Royce and minibus) with per-km, hourly, 5 h and 10 h prices from the market check, city-to-city prices and child seats. Only what is missing is added; nothing you already have is changed. You can change every price afterwards.')
                ->modalSubmitActionLabel('Load it')
                ->action(function () {
                    $n = \App\Support\Transfers\StarterCatalog::load();
                    \Filament\Notifications\Notification::make()->title('Starter list loaded')
                        ->body(collect($n)->map(fn ($v, $k) => $v.' '.$k)->implode(' · '))->success()->send();
                }),
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add service')->modalWidth('3xl'),
        ];
    }
}
