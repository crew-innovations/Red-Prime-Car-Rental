<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Concerns\GuardsSave;
use App\Filament\Resources\PackageResource;
use App\Models\Package;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    use GuardsSave;

    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (Package $record): string => route('crm.preview', ['type' => 'package', 'id' => $record->id]))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
        ];
    }
}
