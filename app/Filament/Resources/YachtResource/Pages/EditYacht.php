<?php

namespace App\Filament\Resources\YachtResource\Pages;

use App\Filament\Resources\YachtResource;
use Filament\Actions;
use App\Filament\Concerns\GuardsSave;
use Filament\Resources\Pages\EditRecord;

class EditYacht extends EditRecord
{
    use GuardsSave;

    protected static string $resource = YachtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Support\ReadinessUi::markReady(Actions\Action::class)
                ->after(fn () => $this->refreshFormData(['status', 'readiness', 'readiness_note'])),
            Actions\DeleteAction::make()->label('Archive')->icon('heroicon-o-archive-box'),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
        ];
    }
}
