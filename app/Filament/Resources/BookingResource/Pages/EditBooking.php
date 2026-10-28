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
            BookingActions::approveHandover(Actions\Action::class)->after($after),
            BookingActions::deliver(Actions\Action::class)->after($after),
            BookingActions::receive(Actions\Action::class)->after($after),
            BookingActions::recordPayment(Actions\Action::class)->after($after),
            BookingActions::refundDeposit(Actions\Action::class)->after($after),
            BookingActions::addExpense(Actions\Action::class)->after($after),
            BookingActions::whatsapp(Actions\Action::class),
            // Customer documents (28 Oct): confirmation, invoice, return statement.
            Actions\ActionGroup::make([
                Actions\Action::make('confirmationPdf')
                    ->label('Booking confirmation (PDF)')
                    ->icon('heroicon-o-document-check')
                    ->visible(fn (): bool => in_array($this->getRecord()->status, \App\Enums\BookingStatus::billable(), true))
                    ->url(fn (): string => route('pdf.confirmation', $this->getRecord()), shouldOpenInNewTab: true),
                Actions\Action::make('issueInvoice')
                    ->label('Issue invoice')
                    ->icon('heroicon-o-document-plus')
                    ->authorize('record-payments')
                    ->visible(fn (): bool => in_array($this->getRecord()->status, \App\Enums\BookingStatus::billable(), true)
                        && ! \App\Models\Invoice::where('booking_id', $this->getRecord()->id)->where('type', 'invoice')->where('status', 'issued')->exists())
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'Issues an invoice for AED '.number_format($this->getRecord()->amountDue(), 2).' with the next number. It cannot be edited afterwards (void it or add a credit note).'
                        .(\App\Support\Tax::enabled() ? ' VAT is on: it is a tax invoice with your TRN.' : ' VAT is off: it is not a tax invoice.'))
                    ->form([\Filament\Forms\Components\Textarea::make('notes')->label('Note on the invoice (optional)')->rows(2)])
                    ->action(function (array $data) {
                        try {
                            $invoice = \App\Models\Invoice::issueFor($this->getRecord(), $data['notes'] ?? null);
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            \Filament\Notifications\Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->title('Invoice '.$invoice->number.' issued')
                            ->actions([\Filament\Notifications\Actions\Action::make('open')->label('Open PDF')->url(route('pdf.invoice', $invoice), shouldOpenInNewTab: true)])
                            ->success()->persistent()->send();
                    }),
                Actions\Action::make('invoicePdf')
                    ->label('Invoice (PDF)')
                    ->icon('heroicon-o-document-currency-dollar')
                    ->visible(fn (): bool => \App\Models\Invoice::where('booking_id', $this->getRecord()->id)->where('type', 'invoice')->where('status', 'issued')->exists())
                    ->url(fn (): ?string => ($i = \App\Models\Invoice::where('booking_id', $this->getRecord()->id)->where('type', 'invoice')->where('status', 'issued')->latest('id')->first()) ? route('pdf.invoice', $i) : null, shouldOpenInNewTab: true),
                Actions\Action::make('statementPdf')
                    ->label(fn (): string => $this->getRecord()->isCar() ? 'Return & damage statement (PDF)' : 'End of service statement (PDF)')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (): bool => (bool) $this->getRecord()->delivered_at)
                    ->url(fn (): string => route('pdf.statement', $this->getRecord()), shouldOpenInNewTab: true),
            ])->label('Documents')->icon('heroicon-o-document-duplicate')->button()->color('gray'),
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
