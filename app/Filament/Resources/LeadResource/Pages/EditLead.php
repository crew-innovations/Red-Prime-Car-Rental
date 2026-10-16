<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\Pipeline;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\Phone;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use App\Filament\Concerns\GuardsSave;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    use GuardsSave;

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
                ->url(Phone::whatsappUrl($lead->whatsapp ?: $lead->phone, \App\Support\Options::message('msg_greeting', ['name' => $lead->name])))
                ->openUrlInNewTab()
                ->visible(filled($lead->whatsapp ?: $lead->phone)),
            Actions\Action::make('call')
                ->label('Call')
                ->icon('heroicon-o-phone')
                ->color('info')
                ->url(Phone::telUrl($lead->phone))
                ->visible(filled($lead->phone)),
            Actions\Action::make('quote')
                ->label('Create quotation')
                ->authorize('manage-quotations')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn (): string => \App\Filament\Resources\QuotationResource::getUrl('create', ['lead' => $this->getRecord()->id]))
                ->visible(fn (): bool => $this->getRecord()->stage !== Pipeline::LOST),
            Actions\Action::make('book')
                ->label('Create booking')
                ->authorize('manage-bookings')
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->url(fn (): string => BookingResource::getUrl('create', ['lead' => $this->getRecord()->id]))
                ->visible(fn (): bool => $this->getRecord()->pipeline !== Pipeline::B2b && $this->getRecord()->stage !== Pipeline::LOST),
            Actions\Action::make('convert')
                ->label('Create customer profile')
                ->authorize('manage-leads')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Creates a customer profile from this lead, or links the existing customer with the same phone or email. The pipeline stage stays the same.')
                ->visible(fn (): bool => ! $this->getRecord()->customer_id)
                ->action(function () {
                    $customer = $this->getRecord()->convertToCustomer();

                    Notification::make()->title('Customer profile ready')->body($customer->name)->success()->send();

                    $this->redirect(LeadResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Actions\ActionGroup::make([
                Actions\Action::make('lost')
                    ->label('Mark as lost')
                    ->authorize('manage-leads')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('lost_reason')
                            ->label('Reason')
                            ->datalist(\App\Support\Options::list('lost_reasons'))
                            ->required(),
                    ])
                    ->visible(fn (): bool => $this->getRecord()->stage !== Pipeline::LOST)
                    ->action(function (array $data) {
                        $this->getRecord()->update(['stage' => Pipeline::LOST, 'lost_reason' => $data['lost_reason']]);
                        $this->refreshFormData(['stage', 'status', 'lost_reason']);
                    }),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
