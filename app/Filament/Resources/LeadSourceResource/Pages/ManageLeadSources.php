<?php

namespace App\Filament\Resources\LeadSourceResource\Pages;

use App\Filament\Resources\LeadSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLeadSources extends ManageRecords
{
    protected static string $resource = LeadSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add source'),
        ];
    }
}
