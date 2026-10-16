<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\Phone;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Lead $lead */
        $lead = $this->getRecord();

        return [
            Actions\Action::make('whatsapp')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->url(Phone::whatsappUrl($lead->whatsapp ?: $lead->phone, "Hi {$lead->name}, this is RED PRIME RENTALS. "))
                ->openUrlInNewTab()
                ->visible(filled($lead->whatsapp ?: $lead->phone)),
            Actions\Action::make('call')
                ->label('Call')
                ->icon('heroicon-o-phone')
                ->color('info')
                ->url(Phone::telUrl($lead->phone))
                ->visible(filled($lead->phone)),
            Actions\Action::make('convert')
                ->label('Convert to customer')
                ->icon('heroicon-o-user-plus')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Creates a customer profile from this lead (or links the existing customer with the same phone or email) and marks the lead as converted.')
                ->visible(fn (): bool => ! $this->getRecord()->customer_id)
                ->action(function () {
                    $customer = $this->getRecord()->convertToCustomer();

                    Notification::make()->title('Lead converted')->body("Customer profile: {$customer->name}")->success()->send();

                    $this->redirect(CustomerResource::getUrl('edit', ['record' => $customer]));
                }),
            Actions\ActionGroup::make([
                Actions\Action::make('lost')
                    ->label('Mark as lost')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('lost_reason')
                            ->label('Reason')
                            ->datalist(['Price too high', 'Car not available', 'Booked with competitor', 'No response', 'Changed plans', 'Not eligible (age / licence)'])
                            ->required(),
                    ])
                    ->visible(fn (): bool => ! in_array($this->getRecord()->status, [LeadStatus::Converted, LeadStatus::Lost], true))
                    ->action(function (array $data) {
                        $this->getRecord()->update(['status' => LeadStatus::Lost, 'lost_reason' => $data['lost_reason']]);
                        $this->refreshFormData(['status', 'lost_reason']);
                    }),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
