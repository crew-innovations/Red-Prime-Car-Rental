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
    use \App\Filament\Concerns\HasNextStep;

    use GuardsSave;

    protected static string $resource = BookingResource::class;

    public function getTitle(): string
    {
        return 'Booking '.$this->getRecord()->reference;
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return $this->progressBar($this->detailsLine()); // 6 Nov (S4): progress bar + the details line
    }

    protected function detailsLine(): ?string
    {
        /** @var Booking $b */
        $b = $this->getRecord();

        return collect([$b->customer?->name, $b->bookable?->name, $b->drive_mode?->getLabel(), $b->status?->getLabel(),
            // 5 Nov (S3): an agent asked to cancel; Admin decides from the menu.
            $b->cancel_requested_at ? 'Cancellation asked by '.(\App\Models\User::find($b->cancel_requested_by)?->name ?? 'an agent').': '.$b->cancel_request_reason : null,
        ])->filter()->implode(' · ');
    }

    protected function afterSave(): void
    {
        BookingResource::logOverride($this->getRecord(), $this->data);
    }

    protected function getHeaderActions(): array
    {
        return $this->withNextStep($this->pageActions()); // 6 Nov (S4): one big next step, the rest under "More"
    }

    /** Every button of this page (the next step is picked from these). */
    protected function pageActions(): array
    {
        $after = fn () => $this->redirect(BookingResource::getUrl('edit', ['record' => $this->getRecord()]));

        return [
            // 11 Nov (limousine): the website price is an estimate; the agent adds extras and confirms it for the customer.
            Actions\Action::make('confirmRide')->label('Confirm price for customer')->icon('heroicon-o-check-badge')->color('primary')
                ->visible(fn (): bool => \App\Support\Transfers\RideFlow::isRequest($this->getRecord()))
                ->modalHeading('Confirm the ride price')
                ->modalDescription(fn (): string => 'Website estimate: AED '.number_format((float) $this->getRecord()->total, 2)
                    .($this->getRecord()->route_km ? ' for '.rtrim(rtrim(number_format((float) $this->getRecord()->route_km, 1), '0'), '.').' km' : '')
                    .'. Add extras if there are any. The customer then sees the final price on their ride page, accepts it and pays.')
                ->modalSubmitActionLabel('Confirm and send to customer')
                ->form([
                    \Filament\Forms\Components\Repeater::make('extras')->label('Extras (optional)')->addActionLabel('Add an extra')->defaultItems(0)->columns(2)->reorderable(false)
                        ->schema([
                            \Filament\Forms\Components\TextInput::make('label')->label('What for')->placeholder('Airport meet & greet, child seat, waiting…')->required()->maxLength(120),
                            \Filament\Forms\Components\TextInput::make('amount')->label('Amount')->numeric()->minValue(0.01)->prefix('AED')->required(),
                        ]),
                    \Filament\Forms\Components\Textarea::make('note')->label('Note for the customer (optional)')->rows(2)->maxLength(300),
                ])
                ->action(function (array $data) {
                    $b = $this->getRecord();
                    $link = \App\Support\Transfers\RideFlow::confirm($b, (array) ($data['extras'] ?? []), $data['note'] ?? null, auth()->user());
                    $b = $b->fresh();
                    $total = 'AED '.number_format((float) $b->balance, 2);
                    \Filament\Notifications\Notification::make()->success()->title('Price confirmed: '.$total)
                        ->body(filled($b->customer?->email) ? 'The customer was emailed the ride page.' : 'No email on the customer: send the ride page on WhatsApp.')->send();
                    if ($p = preg_replace('/\D+/', '', (string) ($b->customer?->whatsapp ?: $b->customer?->phone))) {
                        $text = 'Hello '.strtok((string) $b->customer?->name, ' ').'! Your RED PRIME ride '.$b->reference.' is confirmed at '.$total.'. Accept and pay here, and follow your chauffeur on the same page: '.$link;
                        $this->js('window.open('.json_encode('https://wa.me/'.$p.'?text='.rawurlencode($text)).', "_blank")');
                    }
                })->after($after),
            // The agent sees what the customer sees: the same live map, driver and car.
            Actions\Action::make('liveMap')->label('Live map')->icon('heroicon-o-map')->color('gray')
                ->visible(fn (): bool => $this->getRecord()->service_type === \App\Enums\ServiceType::Transfer
                    && in_array($this->getRecord()->status, [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::InProgress], true))
                ->url(fn (): string => \App\Support\Transfers\Tracking::link($this->getRecord()), true),
            // 6 Nov (limousine L3): the customer's live tracking link, to copy or send on WhatsApp.
            Actions\Action::make('trackingLink')->label('Ride page link')->icon('heroicon-o-map-pin')->color('gray')
                ->visible(fn (): bool => $this->getRecord()->service_type === \App\Enums\ServiceType::Transfer)
                ->modalHeading('The customer\'s ride page')
                ->modalDescription('One page for the whole ride: the price to accept and pay, then the trip step, the car on the map and the ETA, the driver\'s first name, the car and a phone number. No login. It stops working 3 hours after the trip.')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->form(fn (): array => [
                    \Filament\Forms\Components\TextInput::make('link')->label('Link')->default(\App\Support\Transfers\Tracking::link($this->getRecord()))->readOnly()
                        ->suffixAction(\Filament\Forms\Components\Actions\Action::make('wa')->icon('heroicon-o-chat-bubble-left-right')->tooltip('Send on WhatsApp')
                            ->url(fn ($state): ?string => ($p = preg_replace('/\D+/', '', (string) ($this->getRecord()->customer?->whatsapp ?: $this->getRecord()->customer?->phone)))
                                ? 'https://wa.me/'.$p.'?text='.rawurlencode('Hello! Follow your RED PRIME chauffeur live here: '.$state) : null, true)),
                ]),
            \App\Filament\Resources\QuotationResource::approvePriceAction(Actions\Action::class)->after($after),
            \App\Filament\Resources\QuotationResource::rejectPriceAction(Actions\Action::class)->after($after),
            BookingActions::approveHandover(Actions\Action::class)->after($after),
            BookingActions::deliver(Actions\Action::class)->after($after),
            BookingActions::receive(Actions\Action::class)->after($after),
            BookingActions::recordPayment(Actions\Action::class)->after($after),
            \App\Filament\Resources\PaymentRequestResource::linkAction(Actions\Action::class, fn () => $this->getRecord()->fresh())
                ->visible(fn (): bool => ! in_array($this->getRecord()->status, [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow], true)
                    && (\App\Support\Payments\Payments::limit($this->getRecord(), 'balance') > 0 || \App\Support\Payments\Payments::limit($this->getRecord(), 'security_deposit') > 0))
                ->color('gray')->after($after),
            BookingActions::refundDeposit(Actions\Action::class)->after($after),
            BookingActions::settleDeposit(Actions\Action::class)->after($after),
            BookingActions::addExpense(Actions\Action::class)->after($after),
            BookingActions::whatsapp(Actions\Action::class),
            \App\Filament\Support\EmailActions::send(Actions\Action::class),
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
                        && ! \App\Models\Invoice::where('booking_id', $this->getRecord()->id)->where('type', 'invoice')->where('kind', 'booking')->where('status', 'issued')->exists())
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
                // 4 Nov (Phase 4): fines, tolls and fees added after the invoice.
                Actions\Action::make('issueChargesInvoice')
                    ->label('Invoice the new charges')
                    ->icon('heroicon-o-document-plus')
                    ->authorize('record-payments')
                    ->visible(fn (): bool => \App\Models\Invoice::where('booking_id', $this->getRecord()->id)->where('type', 'invoice')->where('kind', 'booking')->where('status', 'issued')->exists()
                        && $this->getRecord()->chargeLines()->whereNull('voided_at')->whereNull('invoice_id')->exists())
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'A separate invoice for the charges not invoiced yet: AED '.number_format((float) $this->getRecord()->chargeLines()->whereNull('voided_at')->whereNull('invoice_id')->sum('amount'), 2).'.')
                    ->action(function () {
                        try {
                            $invoice = \App\Models\Invoice::issueCharges($this->getRecord());
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
                // Job completion report (28 Oct-2): made on completion; can be made again, every version kept.
                Actions\Action::make('jobReport')
                    ->label(fn (): string => 'Job report'.(($r = \App\Support\Documents\JobReports::current($this->getRecord())) ? ' (v'.$r->version.')' : ''))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->visible(fn (): bool => (bool) \App\Support\Documents\JobReports::current($this->getRecord()))
                    ->url(fn (): ?string => \App\Support\Documents\JobReports::current($this->getRecord())?->url(), shouldOpenInNewTab: true),
                Actions\Action::make('makeJobReport')
                    ->label(fn (): string => \App\Support\Documents\JobReports::current($this->getRecord()) ? 'Make the job report again' : 'Make job report')
                    ->icon('heroicon-o-arrow-path')
                    ->authorize('operate-fleet')
                    ->visible(fn (): bool => (bool) $this->getRecord()->delivered_at || $this->getRecord()->status === \App\Enums\BookingStatus::Completed)
                    ->form([\Filament\Forms\Components\TextInput::make('reason')->label('Why?')->placeholder('Return details corrected')->required()->maxLength(200)])
                    ->modalDescription(fn (): string => ($m = \App\Support\Documents\JobReports::missing($this->getRecord())) ? 'Still missing: '.implode(', ', $m).'. The report will show this.' : 'Everything required is recorded.')
                    ->action(function (array $data) {
                        $r = \App\Support\Documents\JobReports::generate($this->getRecord()->fresh(), auth()->user(), $data['reason']);
                        \Filament\Notifications\Notification::make()->title('Job report '.$r->number.' (v'.$r->version.') made')
                            ->actions([\Filament\Notifications\Actions\Action::make('open')->label('Open')->url($r->url(), shouldOpenInNewTab: true)])
                            ->success()->persistent()->send();
                    }),
                Actions\Action::make('jobReportVersions')
                    ->label('Earlier job reports')
                    ->icon('heroicon-o-clock')
                    ->visible(fn (): bool => \App\Models\JobReport::where('booking_id', $this->getRecord()->id)->count() > 1)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn () => new \Illuminate\Support\HtmlString('<ul style="display:flex;flex-direction:column;gap:.5rem">'.\App\Models\JobReport::with('generator')->where('booking_id', $this->getRecord()->id)->orderByDesc('version')->get()
                        ->map(fn ($r) => '<li><a style="font-weight:600;color:rgb(var(--primary-600))" target="_blank" href="'.e($r->url()).'">'.e($r->number.' · v'.$r->version).'</a> · '.e($r->created_at->format('d M Y H:i')).' · '.e($r->generator?->name ?? 'System').' · '.e($r->reason).($r->missing ? ' · <span style="color:rgb(var(--warning-600))">incomplete</span>' : '').'</li>')->implode('').'</ul>')),
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
                // Confirmed transfers keep their rate (29 Oct); pricing again with today's rate is a deliberate step.
                Actions\Action::make('repriceTransfer')
                    ->label('Price again with the current transfer rate')
                    ->icon('heroicon-o-arrow-path')
                    ->authorize('adjust-prices')
                    ->visible(fn (): bool => $this->getRecord()->service_type === \App\Enums\ServiceType::Transfer
                        && in_array($this->getRecord()->status, \App\Enums\BookingStatus::billable(), true) && $this->getRecord()->status !== \App\Enums\BookingStatus::Completed)
                    ->modalDescription('The agreed price was worked out with the rate in force then. This uses today\'s rate instead, and is logged.')
                    ->form([\Filament\Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200)])
                    ->action(function (array $data) {
                        $this->getRecord()->fresh()->repriceWithCurrentRate($data['reason']);
                        \Filament\Notifications\Notification::make()->title('Priced with the current rate')->success()->send();
                        $this->redirect(\App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $this->getRecord()]));
                    }),
                // Supplier cost (29 Oct): Finance sets an exceptional cost or posts the bill by hand.
                Actions\Action::make('supplierCost')
                    ->label('Set supplier cost')
                    ->icon('heroicon-o-building-storefront')
                    ->authorize('manage-finance')
                    ->visible(fn (): bool => (bool) $this->getRecord()->supplier_cost_status && ! \App\Support\Suppliers\SupplierCost::billPosted($this->getRecord()))
                    ->modalDescription(fn (): string => 'Calculated now: '.(($c = \App\Support\Suppliers\SupplierCost::calculate($this->getRecord())) && isset($c['cost']) ? 'AED '.number_format($c['cost'], 2) : ($c['reason'] ?? 'not known')).'. The calculated figure is kept on the record next to yours.')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('amount')->label('Supplier cost for this booking')->numeric()->prefix('AED')->minValue(0)->required()
                            ->default(fn () => $this->getRecord()->supplier_cost),
                        \Filament\Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200)->placeholder('Agreed a special price with the owner'),
                    ])
                    ->action(function (array $data) {
                        \App\Support\Suppliers\SupplierCost::override($this->getRecord(), (float) $data['amount'], $data['reason']);
                        \Filament\Notifications\Notification::make()->title('Supplier cost set')->success()->send();
                        $this->redirect(\App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $this->getRecord()]));
                    }),
                Actions\Action::make('supplierCostClear')
                    ->label('Use the calculated supplier cost')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->authorize('manage-finance')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->getRecord()->supplier_cost_status === 'override' && ! \App\Support\Suppliers\SupplierCost::billPosted($this->getRecord()))
                    ->action(function () {
                        \App\Support\Suppliers\SupplierCost::clearOverride($this->getRecord());
                        $this->redirect(\App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $this->getRecord()]));
                    }),
                Actions\Action::make('supplierBill')
                    ->label('Post the supplier bill')
                    ->icon('heroicon-o-document-plus')
                    ->authorize('manage-finance')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'Adds the bill of AED '.number_format((float) $this->getRecord()->supplier_cost, 2).' to Accounts, once. Normally done automatically when the job is completed.')
                    ->visible(fn (): bool => $this->getRecord()->status === \App\Enums\BookingStatus::Completed && $this->getRecord()->supplier_id
                        && in_array($this->getRecord()->supplier_cost_status, ['calculated', 'override'], true) && ! \App\Support\Suppliers\SupplierCost::billPosted($this->getRecord()))
                    ->action(function () {
                        $bill = \App\Support\Suppliers\SupplierLedger::postForBooking($this->getRecord()->fresh(), 'Posted by hand');
                        \Filament\Notifications\Notification::make()->title($bill ? 'Supplier bill posted' : 'Nothing to post')->success()->send();
                        $this->redirect(\App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $this->getRecord()]));
                    }),
                // Messages (28 Oct-2): conversations stay in the CRM, per booking.
                Actions\Action::make('messageCustomer')
                    ->label('Message the customer')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (): bool => (bool) $this->getRecord()->customer_id)
                    ->action(fn () => $this->redirect(\App\Filament\Pages\Messages::getUrl(['thread' => \App\Support\Chat\Chat::customerThread($this->getRecord())->id]))),
                Actions\Action::make('messageDriver')
                    ->label(fn (): string => 'Message the driver'.($this->getRecord()->driver ? ' ('.$this->getRecord()->driver->name.')' : ''))
                    ->icon('heroicon-o-truck')
                    ->visible(fn (): bool => (bool) $this->getRecord()->driver_id)
                    ->action(fn () => $this->redirect(\App\Filament\Pages\Messages::getUrl(['thread' => \App\Support\Chat\Chat::driverThread($this->getRecord(), $this->getRecord()->driver)->id]))),
                BookingActions::cancel(Actions\Action::class)->after($after),
                BookingActions::requestCancel(Actions\Action::class)->after($after),
                BookingActions::declineCancel(Actions\Action::class)->after($after),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
