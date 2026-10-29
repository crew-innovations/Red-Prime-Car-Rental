<?php

namespace App\Filament\Resources\LeadFormResource\Pages;

use App\Filament\Resources\LeadFormResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLeadForms extends ManageRecords
{
    protected static string $resource = LeadFormResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Make a form')->modalWidth('4xl')];
    }
}
