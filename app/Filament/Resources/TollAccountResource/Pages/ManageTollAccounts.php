<?php

namespace App\Filament\Resources\TollAccountResource\Pages;

use App\Filament\Resources\TollAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTollAccounts extends ManageRecords
{
    protected static string $resource = TollAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Add account')];
    }
}
