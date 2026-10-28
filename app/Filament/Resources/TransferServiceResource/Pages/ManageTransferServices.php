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
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add service')->modalWidth('3xl'),
        ];
    }
}
