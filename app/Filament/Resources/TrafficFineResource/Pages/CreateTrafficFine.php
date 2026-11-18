<?php

namespace App\Filament\Resources\TrafficFineResource\Pages;

use App\Enums\FineStatus;
use App\Filament\Resources\TrafficFineResource;
use App\Support\Fines\Fines;
use Filament\Resources\Pages\CreateRecord;

class CreateTrafficFine extends CreateRecord
{
    protected static string $resource = TrafficFineResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['status' => FineStatus::New, 'created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        Fines::match($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', ['activeTab' => $this->getRecord()->status === FineStatus::Matched ? 'matched' : 'review']);
    }
}
