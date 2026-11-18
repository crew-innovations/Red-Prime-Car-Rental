<?php

namespace App\Filament\Resources\TrafficFineResource\Pages;

use App\Filament\Resources\TrafficFineResource;
use App\Support\Fines\Fines;
use Filament\Resources\Pages\EditRecord;

class EditTrafficFine extends EditRecord
{
    protected static string $resource = TrafficFineResource::class;

    protected function afterSave(): void
    {
        if ($this->getRecord()->wasChanged(['plate', 'car_id', 'offence_at'])) {
            Fines::match($this->getRecord(), 'Fine details changed');
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
