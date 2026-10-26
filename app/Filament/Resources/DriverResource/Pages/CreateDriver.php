<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateDriver extends CreateRecord
{
    protected static string $resource = DriverResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Nobody knows this password; the driver chooses their own through a login link.
        $data['password'] = Str::password(32);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->getRecord()->assignRole('Driver');
    }

    protected function getRedirectUrl(): string
    {
        return DriverResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
