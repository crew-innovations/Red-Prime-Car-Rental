<?php

namespace App\Filament\Resources\PriceExtraResource\Pages;

use App\Filament\Resources\PriceExtraResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManagePriceExtras extends ManageRecords
{
    protected static string $resource = PriceExtraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add extra or fee'),
        ];
    }
}
