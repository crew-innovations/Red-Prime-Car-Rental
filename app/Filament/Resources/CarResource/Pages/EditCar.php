<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use Filament\Actions;
use App\Filament\Concerns\GuardsSave;
use Filament\Resources\Pages\EditRecord;

class EditCar extends EditRecord
{
    use GuardsSave;

    protected static string $resource = CarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Support\ReadinessUi::markReady(Actions\Action::class)
                ->after(fn () => $this->refreshFormData(['status', 'readiness', 'readiness_note'])),
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
        ];
    }
}
