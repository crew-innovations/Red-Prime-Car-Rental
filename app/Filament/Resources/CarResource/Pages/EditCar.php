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
            Actions\Action::make('newContract') // 19 Nov: rental contract for this car
                ->label('New contract')
                ->icon('heroicon-o-document-plus')
                ->color('gray')
                ->visible(fn (): bool => \App\Filament\Resources\RentalContractResource::canCreate() && ! $this->getRecord()->trashed())
                ->url(fn (): string => \App\Filament\Resources\RentalContractResource::getUrl('create', ['item' => $this->getRecord()->getKey()])),
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
        ];
    }
}
