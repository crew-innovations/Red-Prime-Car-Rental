<?php

namespace App\Filament\Resources\TransferFixedRouteResource\Pages;

use App\Filament\Resources\TransferFixedRouteResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferFixedRoutes extends ManageRecords
{
    protected static string $resource = TransferFixedRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add fixed route')->modalWidth('3xl'),
        ];
    }
}
