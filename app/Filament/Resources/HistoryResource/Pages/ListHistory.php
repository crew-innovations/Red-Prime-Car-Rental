<?php

namespace App\Filament\Resources\HistoryResource\Pages;

use App\Filament\Resources\HistoryResource;
use Filament\Resources\Pages\ListRecords;

class ListHistory extends ListRecords
{
    protected static string $resource = HistoryResource::class;

    protected ?string $subheading = 'Who changed what, and when. Read-only.';
}
