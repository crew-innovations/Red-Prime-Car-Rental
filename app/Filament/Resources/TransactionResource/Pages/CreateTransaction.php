<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    /** Preset from the "Record income" / "Record expense" buttons. */
    #[Url(as: 'type')]
    public ?string $presetType = null;

    protected function afterFill(): void
    {
        if (TransactionType::tryFrom((string) $this->presetType)) {
            $this->data['type'] = $this->presetType;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
