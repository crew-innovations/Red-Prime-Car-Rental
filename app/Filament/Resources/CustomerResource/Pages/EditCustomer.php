<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\LeadResource;
use App\Models\Customer;
use App\Support\Phone;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        return [
            Actions\Action::make('whatsapp')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->url(Phone::whatsappUrl($customer->whatsapp ?: $customer->phone, "Hi {$customer->name}, this is RED PRIME RENTALS. "))
                ->openUrlInNewTab()
                ->visible(filled($customer->whatsapp ?: $customer->phone)),
            Actions\Action::make('call')
                ->label('Call')
                ->icon('heroicon-o-phone')
                ->color('info')
                ->url(Phone::telUrl($customer->phone))
                ->visible(filled($customer->phone)),
            Actions\Action::make('newLead')
                ->label('New enquiry')
                ->icon('heroicon-o-plus')
                ->url(LeadResource::getUrl('create', ['customer' => $customer->getKey()])),
            Actions\ActionGroup::make([
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
