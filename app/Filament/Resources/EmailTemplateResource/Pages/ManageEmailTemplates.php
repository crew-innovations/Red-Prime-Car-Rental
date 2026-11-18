<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Resources\EmailTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageEmailTemplates extends ManageRecords
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('announce')->label('Send an announcement')->icon('heroicon-o-megaphone')->color('gray')
                ->url(fn () => \App\Filament\Pages\Announcements::getUrl())->visible(fn () => \App\Filament\Pages\Announcements::canAccess()),
            Actions\CreateAction::make()->label('Add template')->modalWidth('4xl')
                ->mutateFormDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()]),
        ];
    }
}
