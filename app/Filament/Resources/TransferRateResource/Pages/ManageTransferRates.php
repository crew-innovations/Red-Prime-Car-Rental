<?php

namespace App\Filament\Resources\TransferRateResource\Pages;

use App\Filament\Resources\TransferRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferRates extends ManageRecords
{
    protected static string $resource = TransferRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add transfer rate')->modalWidth('3xl'),
        ];
    }
}
