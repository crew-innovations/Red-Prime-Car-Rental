<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Illuminate\Support\Str;
use Filament\Resources\Pages\CreateRecord;

class CreateDriver extends CreateRecord
{
    protected static string $resource = DriverResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // A driver who will not log in still needs a password; set an unguessable one.
        $data['password'] ??= Str::password(24);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->getRecord()->assignRole('Driver');
    }
}
