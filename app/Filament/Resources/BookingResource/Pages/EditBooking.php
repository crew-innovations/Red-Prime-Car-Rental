<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\BookingActions;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\LeadResource;
use App\Models\Booking;
use Filament\Actions;
use App\Filament\Concerns\GuardsSave;
use Filament\Resources\Pages\EditRecord;

class EditBooking extends EditRecord
{
    use GuardsSave;

    protected static string $resource = BookingResource::class;

    public function getTitle(): string
    {
        return 'Booking '.$this->getRecord()->reference;
    }

    public function getSubheading(): ?string
    {
        /** @var Booking $b */
        $b = $this->getRecord();

        return collect([$b->customer?->name, $b->bookable?->name, $b->drive_mode?->getLabel(), $b->status?->getLabel()])->filter()->implode(' · ');
    }

    protected function afterSave(): void
    {
        BookingResource::logOverride($this->getRecord(), $this->data);
    }

    protected function getHeaderActions(): array
    {
        $after = fn () => $this->redirect(BookingResource::getUrl('edit', ['record' => $this->getRecord()]));

        return [
            BookingActions::deliver(Actions\Action::class)->after($after),
            BookingActions::receive(Actions\Action::class)->after($after),
            BookingActions::recordPayment(Actions\Action::class)->after($after),
            BookingActions::refundDeposit(Actions\Action::class)->after($after),
            BookingActions::whatsapp(Actions\Action::class),
            Actions\ActionGroup::make([
                \App\Filament\Support\DocumentActions::requestLink(Actions\Action::class, fn () => $this->getRecord()->customer, fn () => $this->getRecord()),
                Actions\Action::make('quotation')
                    ->label('Open quotation')
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (): bool => (bool) $this->getRecord()->quotation_id)
                    ->url(fn (): ?string => $this->getRecord()->quotation_id ? \App\Filament\Resources\QuotationResource::getUrl('edit', ['record' => $this->getRecord()->quotation_id]) : null),
                Actions\Action::make('customer')
                    ->label('Open customer')
                    ->icon('heroicon-o-user')
                    ->url(fn (): ?string => $this->getRecord()->customer_id ? CustomerResource::getUrl('edit', ['record' => $this->getRecord()->customer_id]) : null),
                Actions\Action::make('lead')
                    ->label('Open lead')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->visible(fn (): bool => (bool) $this->getRecord()->lead_id)
                    ->url(fn (): ?string => $this->getRecord()->lead_id ? LeadResource::getUrl('edit', ['record' => $this->getRecord()->lead_id]) : null),
                BookingActions::cancel(Actions\Action::class)->after($after),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
