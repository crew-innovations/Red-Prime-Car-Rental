<?php

namespace App\Filament\Resources\HistoryResource\Pages;

use App\Filament\Resources\HistoryResource;
use Filament\Resources\Pages\ListRecords;

class ListHistory extends ListRecords
{
    protected static string $resource = HistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Support\Exports\TableExport::action()];
    }

    protected ?string $subheading = 'Who changed what, and when. Read-only.';
}
