<?php

namespace App\Filament\Resources\RentalContractResource;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\RentalContractResource;
use App\Models\Booking;
use App\Models\Car;
use App\Models\ContractDriver;
use App\Models\DamageReport;
use App\Models\RentalContract;
use App\Support\Access;
use App\Support\Contracts\ContractInvoices;
use App\Support\Contracts\Contracts;
use App\Support\Drivers\DriverWork;
use App\Support\Phone;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * 18 Nov: the buttons of a rental contract, on the contract page and (the first few) on the
 * booking page. Every change saves a new PDF version.
 */
class ContractActions
{
    /** Buttons for the booking page: make / open / sign the contract. */
    public static function forBooking(Closure $booking, ?Closure $after = null): array
    {
        $after ??= fn () => null;

        return [
            Action::make('makeContract')
                ->label('Make rental contract')
                ->icon('heroicon-o-document-plus')
                ->authorize('manage-contracts')
                ->visible(fn (): bool => ($b = $booking()) && $b->isCar() && ! $b->contract
                    && ! in_array($b->status, [BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::Inquiry, BookingStatus::Quotation], true))
                ->modalHeading('Make the rental contract')
                ->modalDescription(fn (): string => ($m = \App\Support\Contracts\ConfirmCheck::missing($booking())) ? 'Still missing for the contract: '.implode(', ', $m).'. You can make it now and add them before signing.' : 'Customer details are complete.')
                ->form(fn (): array => self::detailsForm($booking()->customer?->type?->value !== 'individual', in_array($booking()->rental_term, ['long', 'lease'], true)))
                ->action(function (array $data) use ($booking) {
                    try {
                        $c = Contracts::make($booking(), $data);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                        return;
                    }
                    Notification::make()->success()->title('Contract '.$c->number.' made')
                        ->actions([NotificationAction::make('open')->label('Open contract')->url(RentalContractResource::getUrl('view', ['record' => $c]))])->send();
                })->after($after),
            Action::make('openContract')
                ->label(fn (): string => 'Contract '.$booking()?->contract?->number)
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => (bool) $booking()?->contract && RentalContractResource::canViewAny())
                ->url(fn (): ?string => ($c = $booking()?->contract) ? RentalContractResource::getUrl('view', ['record' => $c]) : null),
            self::sign(fn () => $booking()?->contract)->after($after),
        ];
    }

    /** All buttons of the contract page. */
    public static function forContract(Closure $contract, ?Closure $after = null): array
    {
        $after ??= fn () => null;
        $editable = fn (): bool => $contract()->isEditable() && Access::allows('manage-contracts');

        return [
            self::sign($contract)->after($after),
            Action::make('signLink')
                ->label('Send signing link')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->authorize('manage-contracts')
                ->visible(fn (): bool => ! $contract()->signed_at && $contract()->isEditable())
                ->modalHeading('Signing link for the customer')
                ->modalDescription('The customer opens it on their phone, reads the contract and signs. One use; it expires (Contract settings).')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->form(fn (): array => [
                    Forms\Components\TextInput::make('link')->label('Link')->default(Contracts::signLink($contract()))->readOnly()
                        ->suffixAction(Forms\Components\Actions\Action::make('wa')->icon('heroicon-o-chat-bubble-left-right')->tooltip('Send on WhatsApp')
                            ->url(fn ($state): ?string => Phone::whatsappUrl($contract()->customer?->whatsapp ?: $contract()->customer?->phone,
                                'Hello '.strtok((string) $contract()->customer?->name, ' ').'! Please read and sign your RED PRIME rental agreement '.$contract()->number.' here: '.$state), true)),
                ]),
            Action::make('addDriver')
                ->label('Add driver')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->visible($editable)
                ->modalWidth('3xl')
                ->form(self::driverForm())
                ->action(function (array $data) use ($contract) {
                    try {
                        Contracts::addDriver($contract(), $data);
                        Notification::make()->success()->title('Driver added')->body((float) ($data['charge'] ?? 0) > 0 ? 'The charge was added to the booking.' : null)->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    }
                })->after($after),
            Action::make('removeDriver')
                ->label('Remove a driver')
                ->icon('heroicon-o-user-minus')
                ->color('gray')
                ->visible(fn (): bool => $editable() && $contract()->drivers()->where('is_main', false)->exists())
                ->form(fn (): array => [
                    Forms\Components\Select::make('driver_id')->label('Driver')->required()
                        ->options(fn () => $contract()->drivers()->where('is_main', false)->pluck('name', 'id')->all()),
                    Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200),
                ])
                ->action(function (array $data) use ($contract) {
                    Contracts::removeDriver($contract(), ContractDriver::findOrFail($data['driver_id']), $data['reason']);
                    Notification::make()->success()->title('Driver removed')->send();
                })->after($after),
            Action::make('extend')
                ->label('Extend')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (): bool => $editable() && ! in_array($contract()->booking?->status, [BookingStatus::Completed], true))
                ->modalDescription(fn (): string => 'Now returns '.$contract()->booking?->end_at?->format('D d M Y, H:i').'. The price is worked out again for the new dates (shown on the booking).')
                ->form([
                    Forms\Components\DateTimePicker::make('end_at')->label('New return')->native(false)->seconds(false)->minutesStep(15)->required()
                        ->default(fn () => $contract()->booking?->end_at?->copy()->addDay()),
                    Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200)->placeholder('Customer asked for 3 more days'),
                ])
                ->action(function (array $data) use ($contract) {
                    try {
                        Contracts::extend($contract(), $data['end_at'], $data['reason']);
                        Notification::make()->success()->title('Extended')->body('New total: AED '.number_format($contract()->booking->fresh()->amountDue(), 2).'.')->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    }
                })->after($after),
            Action::make('swap')
                ->label('Replace car')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->visible(fn (): bool => $editable() && $contract()->booking?->status === BookingStatus::InProgress)
                ->modalWidth('3xl')
                ->modalDescription(fn (): string => 'Now: '.$contract()->booking?->bookable?->name.'. The first car comes back, the new one goes out; fines and Salik follow each car by time.')
                ->form(fn (): array => [
                    Forms\Components\Select::make('to_car_id')->label('New car')->required()->searchable()
                        ->options(fn () => Car::query()->whereKeyNot($contract()->booking?->bookable_id)->orderBy('name')->get()
                            ->mapWithKeys(fn (Car $c) => [$c->id => $c->name.($c->registration_number ? ' · '.$c->registration_number : '').' · '.$c->status?->getLabel()])->all()),
                    Forms\Components\DateTimePicker::make('swapped_at')->label('When')->native(false)->seconds(false)->default(now())->required(),
                    Forms\Components\Fieldset::make('First car comes back')->columns(2)->schema([
                        Forms\Components\TextInput::make('from_km_in')->label('Odometer')->numeric()->suffix('km')->required(),
                        Forms\Components\Select::make('from_fuel_in_8')->label('Fuel')->options(self::fuelOptions())->native(false),
                    ]),
                    Forms\Components\Fieldset::make('New car goes out')->columns(2)->schema([
                        Forms\Components\TextInput::make('to_km_out')->label('Odometer')->numeric()->suffix('km')->required(),
                        Forms\Components\Select::make('to_fuel_out_8')->label('Fuel')->options(self::fuelOptions())->native(false),
                    ]),
                    Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200)->placeholder('Breakdown, accident, upgrade…'),
                ])
                ->action(function (array $data) use ($contract) {
                    try {
                        Contracts::swap($contract(), $data);
                        Notification::make()->success()->title('Car replaced')->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    }
                })->after($after),
            Action::make('damage')
                ->label('Add damage report')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->visible(fn (): bool => Access::allows('manage-contracts') && in_array($contract()->status, ['draft', 'open', 'closed'], true))
                ->modalWidth('2xl')
                ->form([
                    Forms\Components\ToggleButtons::make('stage')->label('When')->inline()->required()->default('delivery')
                        ->options(['delivery' => 'Car going out (OUT)', 'collection' => 'Car coming back (IN)', 'other' => 'During the rental']),
                    Forms\Components\TextInput::make('area')->label('Where on the car')->maxLength(60)->datalist(['Front bumper', 'Rear bumper', 'Bonnet', 'Roof', 'Windscreen', 'Left side', 'Right side', 'Wheels / rims', 'Interior', 'Mirrors']),
                    Forms\Components\Textarea::make('description')->label('What')->required()->rows(3)->maxLength(1000),
                    Forms\Components\FileUpload::make('photos')->label('Photos')->disk('private')->directory('damage')->visibility('private')->image()->multiple()->maxFiles(10)->maxSize(10240),
                ])
                ->action(function (array $data) use ($contract) {
                    $b = $contract()->booking;
                    $r = DamageReport::create(['booking_id' => $b->id, 'reported_by' => auth()->id(), 'stage' => $data['stage'], 'area' => $data['area'] ?? null,
                        'description' => $data['description'], 'status' => 'open', 'kind' => 'damage']);
                    foreach (\App\Support\PrivateFiles::freshUploads((array) ($data['photos'] ?? []), 'damage') as $path) {
                        $r->addMediaFromDisk($path, 'private')->toMediaCollection('photos');
                    }
                    Contracts::savePdf($contract(), 'Damage noted ('.(['delivery' => 'OUT', 'collection' => 'IN'][$data['stage']] ?? 'during rental').')');
                    Notification::make()->success()->title('Damage report saved')->body('Admin reviews it under Damage reports; a charge is added only after review.')->send();
                })->after($after),
            Action::make('attach')
                ->label('Add attachment')
                ->icon('heroicon-o-paper-clip')
                ->color('gray')
                ->visible(fn (): bool => Access::allows('manage-contracts'))
                ->form([
                    Forms\Components\FileUpload::make('files')->label('Files')->disk('private')->directory('contract-files')->visibility('private')
                        ->acceptedFileTypes(['image/*', 'application/pdf'])->multiple()->maxFiles(10)->maxSize(10240)->required(),
                ])
                ->action(function (array $data) use ($contract) {
                    foreach (\App\Support\PrivateFiles::freshUploads((array) ($data['files'] ?? []), 'contract-files') as $path) {
                        $contract()->addMediaFromDisk($path, 'private')->toMediaCollection('attachments');
                    }
                    Notification::make()->success()->title('Saved')->send();
                })->after($after),
            Action::make('editDetails')
                ->label('Edit contract details')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible($editable)
                ->fillForm(fn (): array => $contract()->only(['type', 'billing_period', 'auto_renew', 'lpo_number', 'company_contact', 'notes']))
                ->form(fn (): array => [...self::detailsForm(true, true), Forms\Components\TextInput::make('reason')->label('Why the change?')->required()->maxLength(200)])
                ->action(function (array $data) use ($contract) {
                    $c = $contract();
                    $c->fill(collect($data)->only(['type', 'billing_period', 'auto_renew', 'lpo_number', 'company_contact', 'notes'])->all());
                    if ($c->isDirty('billing_period') && $c->billing_period === 'monthly' && ! $c->next_invoice_on) {
                        $c->next_invoice_on = ContractInvoices::firstInvoiceDate($c);
                    }
                    $c->save();
                    Contracts::savePdf($c, 'Details changed: '.$data['reason']);
                    Notification::make()->success()->title('Saved, new PDF version made')->send();
                })->after($after),
            Action::make('issuePeriodInvoice')
                ->label('Invoice the next period')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('gray')
                ->authorize('record-payments')
                ->visible(fn (): bool => $contract()->billing_period === 'monthly' && $contract()->status === 'open' && ContractInvoices::nextPeriod($contract()) !== null)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => ($p = ContractInvoices::nextPeriod($contract())) ? 'Invoice for '.$p[0]->format('d M Y').' – '.$p[1]->format('d M Y').'. Normally made by itself on '.($contract()->next_invoice_on?->format('d M Y') ?? 'its date').'.' : '')
                ->action(function () use ($contract) {
                    $i = ContractInvoices::issueNext($contract());
                    Notification::make()->success()->title($i ? 'Invoice '.$i->number.' issued' : 'Nothing to invoice')
                        ->actions($i ? [NotificationAction::make('open')->label('Open PDF')->url(route('pdf.invoice', $i), true)] : [])->send();
                })->after($after),
            Action::make('closeContract')
                ->label('Close contract')
                ->icon('heroicon-o-lock-closed')
                ->color('success')
                ->authorize('manage-contracts')
                ->visible(fn (): bool => $contract()->status === 'open' || ($contract()->status === 'draft' && $contract()->booking?->status === BookingStatus::Completed))
                ->modalHeading('Close the contract')
                ->modalDescription(fn (): HtmlString => new HtmlString($contract()->booking?->status === BookingStatus::Completed
                    ? self::summaryHtml($contract())
                    : '<span style="color:rgb(var(--warning-600));font-weight:600">The car is still out. Use "Receive car" on the booking first: it records km, fuel and the return charges.</span>'))
                ->form(fn (): array => $contract()->booking?->status === BookingStatus::Completed ? [Forms\Components\Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500)] : [])
                ->modalSubmitAction(fn ($action) => $contract()->booking?->status === BookingStatus::Completed ? $action : false)
                ->action(function (array $data) use ($contract) {
                    try {
                        Contracts::close($contract(), $data['note'] ?? null);
                        Notification::make()->success()->title('Contract closed')->body('Settle the deposit from the booking when it is due.')->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    }
                })->after($after),
            Action::make('remakePdf')
                ->label('Make the PDF again')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->authorize('manage-contracts')
                ->form([Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200)->placeholder('Customer details corrected')])
                ->action(function (array $data) use ($contract) {
                    $v = Contracts::savePdf($contract(), $data['reason']);
                    Notification::make()->success()->title($v ? 'Version '.$v->version.' saved' : 'The PDF could not be made (see the log)')->send();
                })->after($after),
            Action::make('openBooking')
                ->label('Open booking')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(fn (): ?string => $contract()->booking ? BookingResource::getUrl('edit', ['record' => $contract()->booking]) : null),
        ];
    }

    /** Sign in the office (tablet / phone handed to the customer). */
    public static function sign(Closure $contract): Action
    {
        return Action::make('signContract')
            ->label('Customer signs here')
            ->icon('heroicon-o-pencil')
            ->authorize('manage-contracts')
            ->visible(fn (): bool => ($c = $contract()) && ! $c->signed_at && $c->isEditable())
            ->modalHeading(fn (): string => 'Sign rental agreement '.$contract()?->number)
            ->modalDescription(fn (): HtmlString => new HtmlString('Hand the screen to the customer. They read the contract (<a target="_blank" style="text-decoration:underline" href="'.e(route('pdf.contract', $contract())).'">open PDF</a>) and sign below.'
                .(($m = \App\Support\Contracts\ConfirmCheck::missing($contract()->booking)) ? '<br><span style="color:rgb(var(--warning-600))">Still missing: '.e(implode(', ', $m)).'.</span>' : '')))
            ->form([
                Forms\Components\TextInput::make('signer')->label('Name of the person signing')->required()->maxLength(120)->default(fn () => $contract()?->customer?->name),
                Forms\Components\ViewField::make('signature')->label('Signature')->view('filament.driver.signature-pad')->rules(['required', 'string'])
                    ->validationMessages(['required' => 'Ask the customer to sign in the box.']),
                Forms\Components\Checkbox::make('agree')->label('The customer read the contract and the terms and agrees to them')->accepted()
                    ->validationMessages(['accepted' => 'Tick that the customer agrees.']),
            ])
            ->action(function (array $data) use ($contract) {
                $png = DriverWork::signaturePng($data['signature'] ?? null);
                if (! $png) {
                    Notification::make()->danger()->title('Ask the customer to sign in the box.')->send();

                    return;
                }
                try {
                    Contracts::sign($contract(), $png, $data['signer'], 'office');
                    Notification::make()->success()->title('Signed')->body('A new PDF version with the signature was saved.')->send();
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                }
            });
    }

    protected static function detailsForm(bool $company, bool $monthly): array
    {
        return [
            Forms\Components\ToggleButtons::make('type')->label('Contract type')->options(RentalContract::TYPES)->inline()->required()
                ->default($monthly ? 'monthly' : ($company ? 'company' : 'individual'))->live(),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('lpo_number')->label('Purchase order (LPO) no.')->maxLength(60)->visible(fn (Get $get): bool => $get('type') === 'company'),
                Forms\Components\TextInput::make('company_contact')->label('Company contact')->maxLength(255)->visible(fn (Get $get): bool => $get('type') === 'company'),
                Forms\Components\Select::make('billing_period')->label('Invoices')->options(['daily' => 'One invoice for the rental', 'monthly' => 'One invoice per month'])
                    ->default($monthly ? 'monthly' : 'daily')->native(false)->selectablePlaceholder(false),
                Forms\Components\Toggle::make('auto_renew')->label('Renew automatically each month')->inline(false)->visible(fn (Get $get): bool => $get('type') === 'monthly'),
            ]),
            Forms\Components\Textarea::make('notes')->label('Notes on the contract (optional)')->rows(2)->maxLength(1000),
        ];
    }

    protected static function driverForm(): array
    {
        $date = fn (string $n, string $l) => Forms\Components\DatePicker::make($n)->label($l)->native(false)->displayFormat('d M Y');

        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('name')->label('Full name')->required()->maxLength(255),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(30),
                Forms\Components\Select::make('nationality')->options(array_combine(\App\Filament\Resources\CustomerResource::NATIONALITIES, \App\Filament\Resources\CustomerResource::NATIONALITIES))->searchable(),
                $date('date_of_birth', 'Date of birth')->maxDate(today())->required(),
                Forms\Components\TextInput::make('id_number')->label('Emirates ID / passport no.')->required()->maxLength(40),
                $date('id_expires_on', 'ID / passport expiry'),
                Forms\Components\TextInput::make('licence_number')->label('Licence number')->required()->maxLength(40),
                Forms\Components\TextInput::make('licence_country')->label('Licence issued in')->maxLength(60),
                $date('licence_expires_on', 'Licence expiry')->required(),
                $date('licence_issued_on', 'Licence issued'),
                Forms\Components\TextInput::make('charge')->label('Additional driver charge')->numeric()->minValue(0)->prefix('AED')
                    ->default(fn () => \App\Models\Setting::read('additional_driver_charge'))
                    ->helperText('Added to the booking as its own line. Empty = no charge.'),
            ]),
        ];
    }

    protected static function fuelOptions(): array
    {
        return collect(range(0, 8))->mapWithKeys(fn ($e) => [$e => \App\Support\Fuel\Fuel::label($e)])->all();
    }

    /** The closing summary in the close pop-up. */
    public static function summaryHtml(RentalContract $c): string
    {
        $s = Contracts::summary($c);
        $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
        $row = fn ($a, $b) => '<tr><td style="padding:.2rem 0;opacity:.8">'.$a.'</td><td style="text-align:right">'.$b.'</td></tr>';
        $html = '<table style="width:100%;font-size:.875rem">';
        if ($s['km_driven'] !== null) {
            $html .= $row('Km driven', number_format($s['km_driven']).' km in '.$s['days'].' day(s)'.($s['km_included'] !== null ? ' · '.number_format($s['km_included']).' included' : ''));
            if ($s['km_extra'] > 0) {
                $html .= $row('Extra km', number_format($s['km_extra']).' km'.($s['extra_km_charge_suggested'] > 0 ? ' = '.$aed($s['extra_km_charge_suggested']).' (check it is in the return charges)' : ''));
            }
        }
        foreach ($s['return_charges'] as $l) {
            $html .= $row(e($l['label']), $aed($l['amount']));
        }
        foreach ($s['other_charges'] as $l) {
            $html .= $row(e(\Illuminate\Support\Str::limit($l['label'], 40)), $aed($l['amount']));
        }
        $html .= $row('<b>Total</b>', '<b>'.$aed($s['total_due']).'</b>').$row('Paid', $aed($s['paid'])).$row('<b>Balance</b>', '<b>'.$aed($s['balance']).'</b>')
            .$row('Deposit held', $aed($s['deposit_held']).($s['deposit_refundable_on'] ? ' · refundable from '.\Illuminate\Support\Carbon::parse($s['deposit_refundable_on'])->format('d M Y') : ''));

        return $html.'</table>';
    }
}
