<?php

namespace App\Filament\Resources\MailAccountResource\Pages;

use App\Filament\Resources\MailAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMailAccounts extends ManageRecords
{
    protected static string $resource = MailAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MailAccountResource::routingAction(),
            Actions\Action::make('log')->label('Email log')->icon('heroicon-o-queue-list')->color('gray')
                ->url(fn () => \App\Filament\Resources\EmailLogResource::getUrl())
                ->visible(fn () => \App\Filament\Resources\EmailLogResource::canViewAny()),
            Actions\CreateAction::make()->label('Add mailbox')
                ->mutateFormDataUsing(fn (array $data) => $data + ['created_by' => auth()->id(), 'status' => 'untested']),
        ];
    }
}
