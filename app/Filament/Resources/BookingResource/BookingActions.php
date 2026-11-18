<?php

namespace App\Filament\Resources\BookingResource;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Car;
use App\Models\User;
use App\Support\BookingFlow;
use App\Support\Phone;
use App\Models\AuditLog;
use App\Support\Access;
use App\Support\Availability;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Booking actions shared by the bookings table (Tables\Actions\Action) and the
 * booking page header (Filament\Actions\Action). Pass the action class to use.
 */
class BookingActions
{
    protected static function aed(float|string|null $v): string
    {
        return 'AED '.number_format((float) $v, 2);
    }

    /** Hand over the car / start the charter. */
    public static function deliver(string $class)
    {
        return $class::make('deliver')
            ->label(fn (Booking $record): string => $record->isCar() ? 'Hand over car' : 'Start '.$record->serviceNoun())
            ->icon('heroicon-o-key')
            ->color('primary')
            ->modalHeading(fn (Booking $record): string => ($record->isCar() ? 'Hand over · ' : 'Start · ').$record->title())
            ->modalDescription(fn (Booking $record): string => $record->customer?->name.' · '.$record->start_at->format('d M, H:i').' → '.$record->end_at->format('d M, H:i'))
            ->modalSubmitActionLabel(fn (Booking $record): string => $record->isCar() ? 'Hand over' : 'Start')
            ->modalWidth('3xl')
            ->authorize('operate-fleet')
            ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true) && ! $record->trashed())
            ->fillForm(fn (Booking $record): array => [
                'delivered_at' => now(),
                'delivery_km' => $record->bookable instanceof Car ? $record->bookable->mileage : null,
                'delivery_fuel' => (string) (($record->bookable instanceof Car ? $record->bookable->fuel_level_8 : null) ?? 8), // 5 Nov (S5): the car's last known level
                'driver_id' => $record->driver_id,
                'deposit_received' => max(0, (float) $record->security_deposit - $record->depositHeld()) ?: null,
                'deposit_method' => PaymentMethod::Card->value,
                'payment_amount' => null, // 6 Nov (S4, H-43 default): a payment is only recorded when staff type it
                'payment_method' => PaymentMethod::Card->value,
                'take_payment' => max(0, (float) $record->security_deposit - $record->depositHeld()) > 0,
            ])
            ->form(fn (Booking $record): array => [
                Forms\Components\Placeholder::make('blockers')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => new HtmlString('<div style="padding:.6rem .8rem;border-radius:.6rem;background:rgba(var(--danger-500),.1);color:rgb(var(--danger-600));font-size:.875rem">'
                        .collect(self::handoverBlockers($record))->map(fn ($m) => '⚠ '.e($m))->implode('<br>').'</div>'))
                    ->visible(fn (): bool => self::handoverBlockers($record) !== []),
                Forms\Components\Toggle::make('override')
                    ->label('Hand over anyway (manager override, recorded in history)')
                    ->visible(fn (): bool => self::handoverBlockers($record) !== [] && Access::allows('override-availability')),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\DateTimePicker::make('delivered_at')
                        ->label($record->isCar() ? 'Handed over at' : 'Started at')
                        ->native(false)->seconds(false)->displayFormat('d M Y, H:i')->required(),
                    Forms\Components\TextInput::make('delivery_km')
                        ->label('Odometer')
                        ->numeric()->minValue(0)->suffix('km')
                        ->required()
                        ->visible($record->isCar()),
                    Forms\Components\ToggleButtons::make('delivery_fuel')
                        ->label('Fuel level')
                        ->options(\App\Support\Fuel\Fuel::gaugeOptions('Empty', 'Full'))
                        ->inline()
                        ->extraAttributes(['class' => 'rp-fuel-gauge'])
                        ->helperText(fn (): ?string => $record->bookable instanceof Car && $record->bookable->fuel_level_8 !== null
                            ? 'Last known: '.\App\Support\Fuel\Fuel::label($record->bookable->fuel_level_8).($record->bookable->fuel_level_at ? ' ('.$record->bookable->fuel_level_at->diffForHumans().')' : '')
                            : null)
                        ->columnSpanFull()
                        ->visible($record->isCar()),
                    Forms\Components\Select::make('driver_id')
                        ->label('Driver')
                        ->options(fn () => self::driverOptions())
                        ->required()
                        ->visible($record->drive_mode === DriveMode::WithDriver)
                        ->columnSpanFull(),
                ]),
                // 6 Nov (S4 / C.2): money only when "Take a payment now" is on (on by default when a deposit is due).
                Forms\Components\Toggle::make('take_payment')
                    ->label('Take a payment or deposit now')
                    ->helperText('Balance due '.self::aed($record->balance).' · deposit booked '.self::aed($record->security_deposit))
                    ->dehydrated(false)
                    ->live(),
                Forms\Components\Fieldset::make('Money received now')
                    ->visible(fn (Forms\Get $get): bool => (bool) $get('take_payment'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('deposit_received')
                            ->label('Security deposit')
                            ->numeric()->minValue(0)->prefix('AED')
                            ->helperText('Booked deposit '.self::aed($record->security_deposit).($record->depositHeld() > 0 ? ' · already held '.self::aed($record->depositHeld()) : '')),
                        Forms\Components\Select::make('deposit_method')
                            ->label('Deposit paid by')
                            ->options(PaymentMethod::class)->native(false),
                        Forms\Components\TextInput::make('payment_amount')
                            ->label('Rental payment')
                            ->numeric()->minValue(0)->prefix('AED')
                            ->helperText('Balance due '.self::aed($record->balance)),
                        Forms\Components\Select::make('payment_method')
                            ->label('Paid by')
                            ->options(PaymentMethod::class)->native(false),
                    ]),
                Forms\Components\FileUpload::make('photos')
                    ->label('Photos (condition at handover)')
                    ->disk('private')->directory('tmp')->visibility('private')
                    ->image()->multiple()->maxFiles(12)->maxSize(8192)
                    ->panelLayout('grid'),
                Forms\Components\Textarea::make('delivery_notes')
                    ->label('Handover notes (optional)')
                    ->placeholder('Existing scratches, items handed over, etc.')
                    ->rows(2),
            ])
            ->action(function (Booking $record, array $data, $action): void {
                $blockers = self::handoverBlockers($record);
                if ($blockers !== []) {
                    if (empty($data['override']) || ! Access::allows('override-availability')) {
                        Notification::make()->title('Cannot hand over yet')->body(implode(' ', $blockers))->danger()->persistent()->send();
                        $action->halt();
                    }
                    AuditLog::note($record, 'override', 'Handed over despite: '.implode(' ', $blockers));
                }

                BookingFlow::deliver($record, $data);

                Notification::make()
                    ->title($record->isCar() ? 'Car handed over' : ucfirst($record->serviceNoun()).' started')
                    ->body(($record->bookable?->name ?? 'Item').' is now marked '.match (true) { $record->isCar() => 'Rented', $record->bookable_type === 'yacht' => 'Booked', default => 'in progress' }.'.')
                    ->success()
                    ->send();
            });
    }

    /** Receive the car back / end the charter, with the payment check. */
    public static function receive(string $class)
    {
        return $class::make('receive')
            ->label(fn (Booking $record): string => $record->isCar() ? 'Receive car' : 'End '.$record->serviceNoun())
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('primary')
            ->modalHeading(fn (Booking $record): string => ($record->isCar() ? 'Receive back · ' : 'End · ').$record->title())
            ->modalDescription(fn (Booking $record): string => $record->customer?->name.' · due back '.$record->end_at->format('d M, H:i')
                .(($job = $record->jobs()->where('type', 'collection')->where('status', 'done')->first()) ? ' · collected by '.($job->doneBy?->name ?? 'the driver').' '.$job->done_at?->format('d M, H:i').': their check is filled in below' : ''))
            ->modalSubmitActionLabel(fn (Booking $record): string => $record->isCar() ? 'Complete return' : 'Complete '.$record->serviceNoun())
            ->modalWidth('4xl')
            ->authorize('operate-fleet')
            ->visible(fn (Booking $record): bool => $record->status === BookingStatus::InProgress && ! $record->trashed())
            ->fillForm(function (Booking $record): array {
                // The driver already checked the car on collection: start from what they recorded.
                $check = $record->jobs()->where('type', 'collection')->where('status', 'done')->value('inspection') ?? [];
                $check = is_array($check) ? $check : (json_decode((string) $check, true) ?: []);
                $condition = $check['condition'] ?? 'good';

                return [
                    'returned_at' => isset($check['at']) ? \Illuminate\Support\Carbon::parse($check['at']) : now(),
                    'return_km' => $check['km'] ?? null,
                    'return_fuel' => (string) ($fuelBack = \App\Support\Fuel\Fuel::eighth($check['fuel'] ?? null) ?? \App\Support\Fuel\Fuel::bookingLevel($record, 'delivery') ?? 8),
                    'return_condition' => $condition,
                    'return_notes' => isset($check['notes']) ? 'Driver: '.$check['notes'] : null,
                    'after_return' => match ($condition) { 'damaged' => 'maintenance', 'minor' => 'inspection', default => 'ready' },
                    'charges' => array_filter([
                        'late' => \App\Support\Rules::lateCharge($record, isset($check['at']) ? \Illuminate\Support\Carbon::parse($check['at']) : now()),
                        'damage' => \App\Models\DamageReport::approvedUnapplied($record->id) ?: null, // approved by a reviewer
                        'fuel' => $record->isCar() ? \App\Support\Fuel\Fuel::proposeCharge($record, $fuelBack)['amount'] : null, // 5 Nov (S5)
                    ]),
                    // 6 Nov (S4 / C.2): only the charges the CRM filled in are shown; the rest are one tap away.
                    'shown_charges' => array_keys(array_filter([
                        'late' => \App\Support\Rules::lateCharge($record, isset($check['at']) ? \Illuminate\Support\Carbon::parse($check['at']) : now()),
                        'damage' => \App\Models\DamageReport::approvedUnapplied($record->id) ?: null,
                        'fuel' => $record->isCar() ? \App\Support\Fuel\Fuel::proposeCharge($record, $fuelBack)['amount'] : null,
                        'extra_km' => isset($check['km']) ? (BookingFlow::extraKmCharge($record, (int) $check['km']) ?: null) : null,
                    ])) ?: ['fuel'],
                    'deduct_from_deposit' => false,
                    'refund_deposit' => false,
                    'payment_method' => PaymentMethod::Card->value,
                    'refund_method' => PaymentMethod::Card->value,
                ];
            })
            ->form(fn (Booking $record): array => [
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\DateTimePicker::make('returned_at')
                        ->label($record->isCar() ? 'Returned at' : 'Ended at')
                        ->native(false)->seconds(false)->displayFormat('d M Y, H:i')->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, $state) => $set('charges.late', \App\Support\Rules::lateCharge($record, $state)))
                        ->helperText(fn (Get $get): ?string => ($h = BookingFlow::hoursLate($record, $get('returned_at')))
                            ? "{$h} hour(s) late.".(\App\Support\Rules::lateCharge($record, $get('returned_at')) ? ' Late charge filled in from your rule; you can change it.' : ' Add a late charge below if needed.')
                            : 'On time'),
                    Forms\Components\TextInput::make('return_km')
                        ->label('Odometer')
                        ->numeric()->minValue($record->delivery_km ?? 0)->suffix('km')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, $state) => $set('charges.extra_km', BookingFlow::extraKmCharge($record, (int) $state) ?: null))
                        ->helperText(fn (Get $get): string => $record->delivery_km && $get('return_km')
                            ? number_format((int) $get('return_km') - $record->delivery_km).' km driven'.($record->bookable instanceof Car && $record->bookable->daily_km_limit ? ' · limit '.number_format($record->bookable->daily_km_limit).' km/day' : '')
                            : 'Handed over at '.number_format((int) $record->delivery_km).' km')
                        ->visible($record->isCar()),
                    Forms\Components\ToggleButtons::make('return_fuel')
                        ->label('Fuel level')
                        ->options(\App\Support\Fuel\Fuel::gaugeOptions('Empty', 'Full'))
                        ->inline()
                        ->extraAttributes(['class' => 'rp-fuel-gauge'])
                        ->live()
                        // 5 Nov (S5): the fuel charge follows the level picked.
                        ->afterStateUpdated(fn (Set $set, $state) => $set('charges.fuel', \App\Support\Fuel\Fuel::proposeCharge($record, \App\Support\Fuel\Fuel::eighth($state))['amount']))
                        ->helperText(fn (Get $get): HtmlString => new HtmlString('Handed over: <b>'.e(\App\Support\Fuel\Fuel::label(\App\Support\Fuel\Fuel::bookingLevel($record, 'delivery'))).'</b>'
                            .(($why = \App\Support\Fuel\Fuel::proposeCharge($record, \App\Support\Fuel\Fuel::eighth($get('return_fuel')))['why']) ? ' · '.e($why) : '')))
                        ->columnSpanFull()
                        ->visible($record->isCar()),
                    Forms\Components\ToggleButtons::make('return_condition')
                        ->label('Condition')
                        ->options(Booking::CONDITIONS)
                        ->colors(['good' => 'success', 'minor' => 'warning', 'damaged' => 'danger'])
                        ->inline()
                        ->live()
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('after_return', match ($state) {
                            'damaged' => 'maintenance',
                            'minor' => 'inspection',
                            default => 'ready',
                        }))
                        ->columnSpanFull(),
                ]),

                Forms\Components\Fieldset::make('Extra charges')
                    ->columns(4)
                    ->schema([Forms\Components\Placeholder::make('charges_note')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->content('Filled in from your rules. Check or change them: they are only charged when you complete the return.'),
                        Forms\Components\Hidden::make('shown_charges')->dehydrated(false),
                        Forms\Components\Toggle::make('more_charges')
                            ->label('Add another charge (extra km, cleaning, Salik, other)')
                            ->dehydrated(false)->live()->columnSpanFull(),
                        ...collect(\App\Support\Options::charges())->map(fn ($meta, $key) => Forms\Components\TextInput::make("charges.{$key}")
                        ->label($meta[0])
                        ->numeric()->minValue(0)->prefix('AED')
                        ->live(onBlur: true)
                        // Damage is only charged once a reviewer approved it (Damage reports).
                        ->readOnly($key === 'damage' && ! Access::allows('review-damage'))
                        ->helperText($key === 'damage' ? (($open = $record->damageReports()->where('status', 'open')->count()) ? $open.' report(s) still to review.' : (Access::allows('review-damage') ? null : 'Approved charges only.')) : null)
                        ->visible(fn (Get $get): bool => ($record->isCar() || ! $meta[2])
                            && ($get('more_charges') || in_array($key, (array) $get('shown_charges'), true) || filled($get("charges.{$key}"))))
                    )->values()->all(),
                        // 5 Nov (S5): charging less fuel than worked out needs a reason (shown on the booking).
                        Forms\Components\TextInput::make('fuel_charge_note')
                            ->label('Why is the fuel charge lower?')
                            ->placeholder('e.g. customer refuelled, gauge reading error, goodwill')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->required(fn (Get $get): bool => self::fuelBelowProposal($record, $get))
                            ->visible(fn (Get $get): bool => self::fuelBelowProposal($record, $get)),
                    ]),

                Forms\Components\Section::make('Payment check')
                    ->icon('heroicon-o-banknotes')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('summary')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(function (Get $get) use ($record): HtmlString {
                                $charges = collect($get('charges') ?? [])->sum(fn ($v) => (float) $v);
                                $owed = (float) $record->balance + $charges;
                                $held = $record->depositHeld();
                                $rows = [
                                    ['Rental balance', self::aed($record->balance)],
                                    ['Extra charges', self::aed($charges)],
                                    ['<b>Total to settle</b>', '<b>'.self::aed($owed).'</b>'],
                                    ['Security deposit held', self::aed($held)],
                                ];
                                $html = '<table style="width:100%;font-size:.875rem">';
                                foreach ($rows as [$a, $b]) {
                                    $html .= "<tr><td style=\"padding:.2rem 0;opacity:.85\">{$a}</td><td style=\"text-align:right\">{$b}</td></tr>";
                                }
                                $status = $owed <= 0
                                    ? '<span style="color:rgb(var(--success-500));font-weight:600">✓ Nothing owed</span>'
                                    : ($held >= $owed
                                        ? '<span style="color:rgb(var(--warning-500));font-weight:600">Deposit covers the amount owed</span>'
                                        : '<span style="color:rgb(var(--danger-500));font-weight:600">Customer still owes '.self::aed($owed - min($held, $owed)).' after the deposit</span>');

                                return new HtmlString($html.'</table><div style="margin-top:.4rem">'.$status.'</div>');
                            }),
                        Forms\Components\Toggle::make('take_payment')
                            ->label('The customer is paying now')
                            ->dehydrated(false)->live()->columnSpanFull(),
                        Forms\Components\TextInput::make('payment_amount')
                            ->label('Payment received now')
                            ->visible(fn (Get $get): bool => (bool) $get('take_payment'))
                            ->numeric()->minValue(0)->prefix('AED'),
                        Forms\Components\Select::make('payment_method')
                            ->label('Paid by')
                            ->visible(fn (Get $get): bool => (bool) $get('take_payment'))
                            ->options(PaymentMethod::class)->native(false),
                        Forms\Components\Toggle::make('deduct_from_deposit')
                            ->label('Take what is still owed from the deposit')
                            ->visible($record->depositHeld() > 0 && Access::allows('manage-finance')),
                        Forms\Components\Toggle::make('refund_deposit')
                            ->label('Refund the rest of the deposit now')
                            ->helperText(fn (): string => ($days = \App\Support\Deposits\Settlement::holdDays())
                                ? 'Business settings hold deposits '.$days.' days after the return for fines and tolls. Leave off and settle it from the booking later.'
                                : 'Leave off to hold it for Salik / fines and settle it later (Settle deposit on the booking).')
                            ->live()
                            ->visible($record->depositHeld() > 0 && Access::allows('manage-finance')),
                        Forms\Components\Placeholder::make('deposit_note')
                            ->hiddenLabel()
                            ->content('The deposit stays held. Finance can deduct or refund it from the booking.')
                            ->visible($record->depositHeld() > 0 && ! Access::allows('manage-finance')),
                        Forms\Components\Select::make('refund_method')
                            ->label('Refund by')
                            ->options(PaymentMethod::class)->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('refund_deposit') && $record->depositHeld() > 0),
                    ]),

                Forms\Components\FileUpload::make('photos')
                    ->label('Photos (condition at return)')
                    ->disk('private')->directory('tmp')->visibility('private')
                    ->image()->multiple()->maxFiles(12)->maxSize(8192)
                    ->panelLayout('grid'),
                Forms\Components\Textarea::make('return_notes')
                    ->label('Return notes')
                    ->rows(2),
                Forms\Components\ToggleButtons::make('after_return')
                    ->label($record->isCar() ? 'After this return, the car is' : 'After this charter, the yacht is')
                    ->options([
                        'ready' => 'Checked and ready',
                        'cleaning' => 'Needs cleaning',
                        'inspection' => 'Needs inspection',
                        'maintenance' => 'Damaged: send to maintenance',
                    ])
                    ->colors(['ready' => 'success', 'cleaning' => 'warning', 'inspection' => 'warning', 'maintenance' => 'danger'])
                    ->inline()
                    ->required()
                    ->helperText('It can be booked again, but it can only be handed over once it is marked ready.')
                    ->visible($record->isCar() || $record->bookable_type === 'yacht'),
            ])
            ->action(function (Booking $record, array $data): void {
                $result = BookingFlow::receive($record, $data);

                $lines = [];
                $lines[] = match ($data['after_return'] ?? 'ready') {
                    'maintenance' => 'Sent to maintenance.',
                    'cleaning' => 'Marked "Needs cleaning". Mark it ready before the next handover.',
                    'inspection' => 'Marked "Needs inspection". Mark it ready before the next handover.',
                    default => ($record->bookable?->name ?? 'Item').' is ready for the next booking.',
                };
                $lines[] = $result['balance'] > 0
                    ? self::aed($result['balance']).' still to collect (added to Accounts › To collect).'
                    : 'Payment cleared.';
                if ($result['refunded'] > 0) {
                    $lines[] = 'Deposit refunded: '.self::aed($result['refunded']).'.';
                } elseif ($result['held'] > 0) {
                    $lines[] = 'Deposit held: '.self::aed($result['held']).'.';
                }

                Notification::make()
                    ->title($record->isCar() ? 'Car received' : ucfirst($record->serviceNoun()).' completed')
                    ->body(implode(' ', $lines))
                    ->color($result['balance'] > 0 ? 'warning' : 'success')
                    ->icon($result['balance'] > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                    ->persistent()
                    ->send();
            });
    }

    /** The driver's handover check waits for the office (Business settings › Drivers). */
    public static function approveHandover(string $class)
    {
        $waiting = fn (Booking $record) => $record->jobs()->where('type', 'delivery')->where('status', 'waiting')->first();

        return $class::make('approveHandover')
            ->label('Approve handover')
            ->icon('heroicon-o-check-badge')
            ->color('warning')
            ->authorize('operate-fleet')
            ->visible(fn (Booking $record): bool => (bool) $waiting($record))
            ->modalHeading(fn (Booking $record): string => 'Approve handover · '.$record->title())
            ->modalDescription('The driver is with the customer and waits for your OK before giving the keys.')
            ->modalContent(function (Booking $record) use ($waiting): HtmlString {
                $job = $waiting($record);
                $c = (array) $job?->inspection;
                $rows = array_filter([
                    'Driver' => $job?->driver?->name,
                    'Checked at' => isset($c['at']) ? \Illuminate\Support\Carbon::parse($c['at'])->format('d M H:i') : null,
                    'Odometer' => isset($c['km']) ? number_format((int) $c['km']).' km' : null,
                    'Fuel' => isset($c['fuel']) ? \App\Support\Fuel\Fuel::label($c['fuel']) : null,
                    'Photos' => (int) ($c['photos'] ?? 0),
                    'Notes' => $c['notes_text'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                $html = '<table style="width:100%;font-size:.875rem">';
                foreach ($rows as $k => $v) {
                    $html .= '<tr><td style="padding:.2rem 0;opacity:.7">'.e($k).'</td><td style="text-align:right">'.e((string) $v).'</td></tr>';
                }
                $html .= '</table><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.4rem;margin-top:.75rem">';
                foreach ($record->getMedia('delivery')->sortByDesc('id')->take(12) as $m) {
                    $url = e($m->getTemporaryUrl(now()->addMinutes(10)));
                    $html .= '<a href="'.$url.'" target="_blank" rel="noopener"><img src="'.$url.'" alt="" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:.5rem"></a>';
                }

                return new HtmlString($html.'</div>');
            })
            ->modalSubmitActionLabel('OK, release the car')
            ->extraModalFooterActions(fn (Booking $record) => [
                $class::make('rejectHandover')
                    ->label('Not OK')
                    ->color('danger')
                    ->form([Forms\Components\TextInput::make('reason')->label('What should the driver do?')->required()->maxLength(200)])
                    ->action(function (array $data) use ($record, $waiting) {
                        \App\Support\Drivers\DriverWork::rejectHandover($waiting($record), auth()->user(), $data['reason']);
                        Notification::make()->title('The driver was told')->success()->send();
                    })
                    ->cancelParentActions(),
            ])
            ->action(function (Booking $record) use ($waiting) {
                try {
                    \App\Support\Drivers\DriverWork::approveHandover($waiting($record), auth()->user());
                } catch (\Illuminate\Validation\ValidationException $e) {
                    Notification::make()->title('Not released')->body(collect($e->errors())->flatten()->implode(' '))->danger()->persistent()->send();

                    return;
                }
                Notification::make()->title('Handover approved. The driver was told.')->success()->send();
            });
    }

    /** 5 Nov (S5): the fuel charge typed is lower than what the CRM worked out. */
    protected static function fuelBelowProposal(Booking $record, Get $get): bool
    {
        if (! $record->isCar()) {
            return false;
        }
        $proposed = \App\Support\Fuel\Fuel::proposeCharge($record, \App\Support\Fuel\Fuel::eighth($get('return_fuel')))['amount'];

        return $proposed !== null && (float) $get('charges.fuel') + 0.005 < $proposed;
    }

    /** A cost for this booking (fuel, parking, delivery…), for the booking's profit (28 Oct, 8.2). */
    public static function addExpense(string $class)
    {
        return $class::make('addExpense')
            ->label('Add expense')
            ->icon('heroicon-o-arrow-up-right')
            ->color('gray')
            ->authorize('record-expenses')
            ->modalWidth('lg')
            ->modalDescription(fn (): string => Access::allows('manage-finance') ? 'Counted as a cost of this booking.' : 'Finance approves it before it counts.')
            ->form([
                Forms\Components\Select::make('category')->options(fn () => collect(\App\Models\Transaction::categories('expense'))->except(\App\Models\Transaction::NON_COST_CATEGORIES)->all())->required()->native(false),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('amount')->numeric()->minValue(0.01)->prefix('AED')->required(),
                    Forms\Components\DatePicker::make('date')->default(today())->native(false)->required(),
                ]),
                Forms\Components\TextInput::make('vendor')->label('Paid to')->maxLength(255),
                Forms\Components\TextInput::make('description')->maxLength(255),
                Forms\Components\Select::make('method')->label('Paid by')->options(PaymentMethod::class)->native(false),
                Forms\Components\Toggle::make('is_estimate')->label('Estimate (not final yet)'),
                Forms\Components\FileUpload::make('receipt')->label('Receipt photo / PDF')
                    ->disk('private')->directory('tmp')->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->maxSize(10240),
                \App\Filament\Concerns\ReceiptReader::button('receipt'), // 18 Nov (meeting item 7)
            ])
            ->action(function (Booking $record, array $data) {
                $t = \App\Models\Transaction::create([
                    'type' => \App\Enums\TransactionType::Expense,
                    'booking_id' => $record->id,
                    'category' => $data['category'],
                    'amount' => $data['amount'],
                    'date' => $data['date'],
                    'status' => \App\Enums\PaymentStatus::Paid,
                    'method' => $data['method'] ?? null,
                    'vendor' => $data['vendor'] ?? null,
                    'description' => $data['description'] ?? null,
                    'is_estimate' => (bool) ($data['is_estimate'] ?? false),
                ]);
                // Action forms do not save files on their own.
                if (! empty($data['receipt']) && $paths = \App\Support\PrivateFiles::freshUploads((array) $data['receipt'], 'tmp')) {
                    foreach ($paths as $path) {
                        $t->addMediaFromDisk($path, 'private')->toMediaCollection('receipts');
                    }
                }
                Notification::make()->title($t->approval_status === 'pending' ? 'Expense saved: waiting for approval' : 'Expense saved')->success()->send();
            });
    }

    public static function recordPayment(string $class)
    {
        return $class::make('payment')
            ->label('Record payment')
            ->authorize('record-payments')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->modalWidth('lg')
            ->visible(fn (Booking $record): bool => (float) $record->balance > 0 && ! $record->trashed())
            ->fillForm(fn (Booking $record): array => ['amount' => (float) $record->balance, 'method' => PaymentMethod::Card->value])
            ->form(fn (Booking $record): array => [
                Forms\Components\TextInput::make('amount')
                    ->numeric()->minValue(0.01)->prefix('AED')->required()
                    ->helperText('Balance due '.self::aed($record->balance)),
                Forms\Components\Select::make('method')
                    ->label('Paid by')
                    ->options(PaymentMethod::class)->native(false)->required(),
                Forms\Components\TextInput::make('reference')
                    ->placeholder('Receipt / bank ref')
                    ->maxLength(100),
            ])
            ->action(function (Booking $record, array $data): void {
                $record->recordPayment((float) $data['amount'], $data['method'], null, null, $data['reference'] ?? null);
                $record->refresh();

                Notification::make()
                    ->title('Payment recorded')
                    ->body((float) $record->balance > 0 ? 'Still to collect: '.self::aed($record->balance) : 'Booking fully paid.')
                    ->success()
                    ->send();
            });
    }

    public static function refundDeposit(string $class)
    {
        return $class::make('refundDeposit')
            ->label('Refund deposit')
            ->authorize('manage-finance')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->modalWidth('lg')
            ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::NoShow], true) && $record->depositHeld() > 0)
            ->fillForm(fn (Booking $record): array => ['amount' => $record->depositHeld(), 'method' => PaymentMethod::Card->value])
            ->form(fn (Booking $record): array => [
                Forms\Components\TextInput::make('amount')
                    ->numeric()->minValue(0.01)->maxValue($record->depositHeld())->prefix('AED')->required()
                    ->helperText('Held: '.self::aed($record->depositHeld()).'. To keep part for fines, record the fine as a payment first.'),
                Forms\Components\Select::make('method')
                    ->label('Refund by')
                    ->options(PaymentMethod::class)->native(false)->required(),
            ])
            ->action(function (Booking $record, array $data): void {
                BookingFlow::refundDeposit($record, (float) $data['amount'], $data['method']);

                Notification::make()->title('Deposit refunded')->body(self::aed($data['amount']))->success()->send();
            });
    }

    /**
     * 4 Nov (4.2): settle the deposit against what is still owed (return charges, fines, tolls,
     * approved damage); refund the rest; ask for anything above the deposit. Finance only.
     */
    public static function settleDeposit(string $class)
    {
        return $class::make('settleDeposit')
            ->label('Settle deposit')
            ->authorize('manage-finance')
            ->icon('heroicon-o-scale')
            ->color('primary')
            ->modalWidth('xl')
            ->visible(fn (Booking $record): bool => \App\Support\Deposits\Settlement::canSettle($record))
            ->modalHeading(fn (Booking $record): string => 'Settle the deposit · '.$record->reference)
            ->modalSubmitActionLabel('Settle now')
            ->form(function (Booking $record): array {
                $p = \App\Support\Deposits\Settlement::preview($record);
                $rows = collect($p['lines'])->map(fn ($l) => '<tr><td>'.e($l['label']).'</td><td style="text-align:right;white-space:nowrap">'.e(self::aed($l['amount'])).'</td></tr>')->implode('');
                $sum = '<tr><td><b>Still owed</b></td><td style="text-align:right"><b>'.e(self::aed($p['owed'])).'</b></td></tr>'
                    .'<tr><td>Deposit held</td><td style="text-align:right">'.e(self::aed($p['held'])).'</td></tr>'
                    .'<tr><td>Deposit used for what is owed</td><td style="text-align:right">'.e(self::aed($p['apply'])).'</td></tr>'
                    .'<tr><td><b>Refund to the customer</b></td><td style="text-align:right"><b>'.e(self::aed($p['refund'])).'</b></td></tr>'
                    .($p['to_collect'] > 0 ? '<tr><td><b>Still to collect after the deposit</b></td><td style="text-align:right"><b>'.e(self::aed($p['to_collect'])).'</b></td></tr>' : '');

                return array_values(array_filter([
                    $p['early'] ? Forms\Components\Placeholder::make('early')->hiddenLabel()
                        ->content(new HtmlString('<span style="color:rgb(var(--warning-600))">The hold period ends on '.e($record->deposit_refundable_on->format('d M Y')).'. Fines and tolls can still arrive. Settle early only if you are sure.</span>')) : null,
                    Forms\Components\Placeholder::make('statement')->hiddenLabel()
                        ->content(new HtmlString('<table class="rp-simple-table"><tbody>'.$rows.'</tbody><tfoot>'.$sum.'</tfoot></table>')),
                    $p['refund'] > 0 ? Forms\Components\Select::make('refund_method')->label('Refund by')->options(PaymentMethod::class)->native(false)->required() : null,
                    $record->preauths()->where('status', 'held')->exists() ? Forms\Components\TextInput::make('preauth_release_ref')->label('Card hold released (reference)')->maxLength(80) : null,
                    $p['to_collect'] > 0 ? Forms\Components\Toggle::make('link')->label('Make a payment link for the rest')->default(true)
                        ->helperText('Needs a payment gateway in Settings › Integrations.') : null,
                    Forms\Components\Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500),
                ]));
            })
            ->action(function (Booking $record, array $data): void {
                try {
                    $s = \App\Support\Deposits\Settlement::settle($record, [
                        'refund_method' => ($m = $data['refund_method'] ?? null) instanceof \BackedEnum ? $m->value : $m,
                        'link' => (bool) ($data['link'] ?? false), 'note' => $data['note'] ?? null, 'preauth_release_ref' => $data['preauth_release_ref'] ?? null,
                    ]);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    Notification::make()->title('Not settled')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();

                    return;
                }
                Notification::make()->title('Deposit settled')
                    ->body('Used '.self::aed($s->applied).' · refunded '.self::aed($s->refunded).($s->to_collect > 0 ? ' · still to collect '.self::aed($s->to_collect) : ''))->success()->send();
            });
    }

    /** Statuses that can still be cancelled. */
    public const CANCELLABLE = [BookingStatus::Inquiry, BookingStatus::Quotation, BookingStatus::PendingPayment, BookingStatus::Confirmed];

    /**
     * 5 Nov (S3, B.5 / H-36): Admin cancels any booking. An agent cancels an unpaid booking
     * themselves (Admin is always told); a booking with money received waits for Admin.
     */
    public static function canCancelNow(Booking $b): bool
    {
        if (Access::isAdmin()) {
            return true;
        }

        return (float) $b->paid_amount <= 0 && (bool) \App\Models\Setting::read('agents_can_cancel_unpaid', true);
    }

    public static function cancel(string $class)
    {
        return $class::make('cancelBooking') // not 'cancel': that name is taken by the form's Cancel button
            ->label('Cancel booking')
            ->authorize('manage-bookings')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalWidth('lg')
            ->visible(fn (Booking $record): bool => in_array($record->status, self::CANCELLABLE, true) && ! $record->trashed() && self::canCancelNow($record))
            ->form([
                Forms\Components\ToggleButtons::make('status')
                    ->hiddenLabel()
                    ->options([BookingStatus::Cancelled->value => 'Cancelled', BookingStatus::NoShow->value => 'No-show'])
                    ->colors([BookingStatus::Cancelled->value => 'danger', BookingStatus::NoShow->value => 'danger'])
                    ->default(fn (Booking $record) => $record->cancel_requested_at ? BookingStatus::Cancelled->value : BookingStatus::Cancelled->value)
                    ->inline()
                    ->required(),
                Forms\Components\TextInput::make('cancel_reason')
                    ->label('Reason')
                    ->default(fn (Booking $record) => $record->cancel_request_reason)
                    ->datalist(\App\Support\Options::list('cancel_reasons'))
                    ->required(),
            ])
            ->modalDescription(fn (Booking $record): string => (float) $record->paid_amount > 0
                ? self::aed($record->paid_amount).' was already received. Record any refund in Accounts.'
                : 'The car / yacht will be free for these dates again.')
            ->action(function (Booking $record, array $data): void {
                abort_unless(self::canCancelNow($record), 403);
                $requestedBy = $record->cancel_requested_by ? \App\Models\User::find($record->cancel_requested_by) : null;
                $record->update(['status' => $data['status'], 'cancel_reason' => $data['cancel_reason'],
                    'cancel_requested_at' => null, 'cancel_requested_by' => null, 'cancel_request_reason' => null]);

                $url = \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $record]);
                if (! Access::isAdmin()) {
                    \App\Support\Drivers\Notify::staff('assign-leads', 'Booking '.$record->reference.' cancelled by '.auth()->user()->name,
                        'No money received. Reason: '.$data['cancel_reason'], $url, null, 'warning', 'cancel_requested', 'cancelled:'.$record->id);
                } elseif ($requestedBy) {
                    \App\Support\Drivers\Notify::person($requestedBy, 'Cancellation approved: '.$record->reference, $data['cancel_reason'], $url, 'success', 'cancel_requested', 'cancel-decided:'.$record->id.':'.now()->timestamp);
                }

                Notification::make()->title('Booking '.strtolower(BookingStatus::from($data['status'])->getLabel()))->success()->send();
            });
    }

    /**
     * 18 Nov (meeting items 5 and 8): Admin confirms the booking. It is refused while the customer's
     * details or documents are incomplete, unless Admin confirms anyway with a reason (recorded).
     */
    public static function confirm(string $class)
    {
        return $class::make('confirmBooking')
            ->label('Confirm booking')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->authorize('change-booking-status')
            ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Inquiry, BookingStatus::Quotation, BookingStatus::PendingPayment], true) && ! $record->trashed())
            ->modalHeading(fn (Booking $record): string => 'Confirm '.$record->reference)
            ->modalDescription(function (Booking $record): string {
                $missing = \App\Support\Contracts\ConfirmCheck::enabled() ? \App\Support\Contracts\ConfirmCheck::missing($record) : [];

                return $missing ? 'Still missing: '.implode(', ', $missing).'. Add them (customer pencil on the booking) or confirm anyway with a reason.' : 'The car is held for these dates and the customer is told.';
            })
            ->form(fn (Booking $record): array => \App\Support\Contracts\ConfirmCheck::enabled() && \App\Support\Contracts\ConfirmCheck::missing($record) ? [
                Forms\Components\Toggle::make('anyway')->label('Confirm anyway (recorded in the history)')->live(),
                Forms\Components\TextInput::make('reason')->label('Why?')->maxLength(255)->required(fn (Get $get): bool => (bool) $get('anyway'))->visible(fn (Get $get): bool => (bool) $get('anyway')),
            ] : [])
            ->action(function (Booking $record, array $data): void {
                $missing = \App\Support\Contracts\ConfirmCheck::enabled() ? \App\Support\Contracts\ConfirmCheck::missing($record) : [];
                if ($missing && empty($data['anyway'])) {
                    Notification::make()->danger()->title('Not confirmed')->body('Still missing: '.implode(', ', $missing).'.')->send();

                    return;
                }
                Booking::$confirmOverride = (bool) $missing;
                try {
                    $record->status = BookingStatus::Confirmed;
                    $record->save();
                } catch (\Illuminate\Validation\ValidationException $e) {
                    Notification::make()->danger()->title('Not confirmed')->body(collect($e->errors())->flatten()->first())->send();

                    return;
                } finally {
                    Booking::$confirmOverride = false;
                }
                if ($missing) {
                    AuditLog::note($record, 'override', 'Confirmed with missing details ('.implode(', ', $missing).'): '.($data['reason'] ?? ''));
                }
                Notification::make()->success()->title('Booking confirmed')->send();
            });
    }

    /** 5 Nov (S3): an agent asks Admin to cancel a booking that already has money received. */
    public static function requestCancel(string $class)
    {
        return $class::make('requestCancel')
            ->label('Ask Admin to cancel')
            ->authorize('manage-bookings')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Booking $record): bool => in_array($record->status, self::CANCELLABLE, true) && ! $record->trashed()
                && ! self::canCancelNow($record) && ! $record->cancel_requested_at)
            ->modalDescription(fn (Booking $record): string => self::aed($record->paid_amount).' was already received, so an Admin decides. You will be told the answer.')
            ->form([Forms\Components\TextInput::make('reason')->label('Why cancel?')->datalist(\App\Support\Options::list('cancel_reasons'))->required()->maxLength(500)])
            ->action(function (Booking $record, array $data): void {
                $record->forceFill(['cancel_requested_at' => now(), 'cancel_requested_by' => auth()->id(), 'cancel_request_reason' => $data['reason']])->save();
                rescue(fn () => \App\Models\AuditLog::note($record, 'cancel_request', 'Cancellation asked: '.$data['reason']), report: false);
                \App\Support\Drivers\Notify::staff('assign-leads', 'Cancellation to approve: '.$record->reference,
                    auth()->user()->name.': '.$data['reason'].' · '.self::aed($record->paid_amount).' received', \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $record]),
                    null, 'danger', 'cancel_requested', 'cancel-request:'.$record->id.':'.now()->timestamp,
                    [['approveCancel', 'Approve', 'rp-approve-cancel', ['booking' => $record->id], 'danger']]);
                Notification::make()->title('Sent to Admin')->body('You will be told when they decide.')->success()->send();
            });
    }

    /** 5 Nov (S3): Admin turns a cancellation request down. */
    public static function declineCancel(string $class)
    {
        return $class::make('declineCancel')
            ->label('Keep the booking (turn cancellation down)')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Booking $record): bool => (bool) $record->cancel_requested_at && Access::isAdmin())
            ->form([Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(300)])
            ->action(function (Booking $record, array $data): void {
                $by = $record->cancel_requested_by ? \App\Models\User::find($record->cancel_requested_by) : null;
                $record->forceFill(['cancel_requested_at' => null, 'cancel_requested_by' => null, 'cancel_request_reason' => null])->save();
                rescue(fn () => \App\Models\AuditLog::note($record, 'cancel_request', 'Cancellation turned down: '.$data['reason']), report: false);
                \App\Support\Drivers\Notify::person($by, 'Cancellation turned down: '.$record->reference, $data['reason'], \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $record]), 'warning', 'cancel_requested', 'cancel-decided:'.$record->id.':'.now()->timestamp);
                Notification::make()->title('The booking stays')->success()->send();
            });
    }

    public static function whatsapp(string $class)
    {
        return $class::make('whatsapp')
            ->label('WhatsApp')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('gray')
            ->url(fn (Booking $record): ?string => Phone::whatsappUrl(
                $record->customer?->whatsapp ?: $record->customer?->phone,
                \App\Support\Options::message('msg_booking', [
                    'name' => $record->customer?->name,
                    'reference' => $record->reference,
                    'item' => $record->bookable?->name ?? '',
                    'start' => $record->start_at->format('d M, H:i'),
                    'end' => $record->end_at->format('d M, H:i'),
                    'amount' => number_format((float) $record->balance),
                    'balance_line' => (float) $record->balance > 0 ? ' Balance due: AED '.number_format((float) $record->balance).'.' : '',
                ])
            ))
            ->openUrlInNewTab()
            ->visible(fn (Booking $record): bool => filled($record->customer?->whatsapp ?: $record->customer?->phone));
    }

    /** Reasons a booking can't be handed over right now (empty = fine). */
    public static function handoverBlockers(Booking $record, bool $signedOnPhone = false): array
    {
        $blockers = [];
        if ($record->bookable_type && $record->bookable_id && ($msg = Availability::restriction($record->bookable_type, (int) $record->bookable_id))) {
            $blockers[] = $msg;
        }
        if ($msg = Availability::notReady($record->bookable)) {
            $blockers[] = $msg;
        }
        if ($out = Availability::stillOut($record)) {
            $blockers[] = 'Still out with another customer on '.$out->reference.' (due back '.$out->end_at->format('d M H:i').'). Receive it first.';
        }
        foreach ($record->missingDocuments() as $label) {
            $blockers[] = 'Missing approved document: '.$label.'.';
        }
        // 18 Nov: with "hand over only with a signed contract" on (Contract settings).
        if ($record->isCar() && ! $signedOnPhone && \App\Models\Setting::read('contract_required_for_handover')
            && ! ($contract = \App\Models\RentalContract::where('booking_id', $record->id)->first())?->signed_at) {
            $blockers[] = $contract ? 'The rental contract '.$contract->number.' is not signed yet.' : 'There is no rental contract yet (Make rental contract).';
        }

        return $blockers;
    }

    /** Marker in a driver option label when the driver is busy at that time (option disabled). */
    public const BUSY = ' · busy on ';

    /** Active drivers; usual drivers of the car first; busy ones marked when the times are known. */
    public static function driverOptions(?int $carId = null, $start = null, $end = null, $driveMode = null, ?int $ignoreBookingId = null): array
    {
        $usual = $carId ? Car::find($carId)?->drivers()->pluck('users.id')->all() ?? [] : [];

        return User::role('Driver')->with('driverProfile')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => ! $u->driverProfile || $u->driverProfile->does('driver'))
            ->sortBy(fn (User $u) => in_array($u->id, $usual, true) ? 0 : 1, SORT_REGULAR)
            ->mapWithKeys(function (User $u) use ($usual, $start, $end, $driveMode, $ignoreBookingId) {
                $clash = $start ? Availability::personConflict($u->id, $start, $end ?: $start, $ignoreBookingId, $driveMode ?: DriveMode::SelfDrive->value) : null;
                $off = $start && ! $clash ? Availability::offTime($u->id, $start, $end ?: $start, $driveMode ?: DriveMode::SelfDrive->value) : null;

                return [$u->id => $u->name
                    .(in_array($u->id, $usual, true) ? ' ★ usual driver' : '')
                    .($u->phone ? ' · '.$u->phone : '')
                    .($clash ? self::BUSY.$clash->reference.' '.$clash->start_at->format('d M H:i') : '')
                    .($off ? ' · not available ('.($off->reason ?: 'off').')' : '')];
            })
            ->all();
    }

    /** Captains (role "captain") or crew (crew or captain) for a charter, busy ones marked. */
    public static function crewOptions(string $role, $start = null, $end = null, ?int $ignoreBookingId = null): array
    {
        return User::role('Driver')->with('driverProfile')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->driverProfile && ($u->driverProfile->does($role) || ($role === 'crew' && $u->driverProfile->does('captain'))))
            ->mapWithKeys(function (User $u) use ($start, $end, $ignoreBookingId) {
                $clash = $start ? Availability::personConflict($u->id, $start, $end ?: $start, $ignoreBookingId, DriveMode::WithDriver) : null;
                $off = $start && ! $clash ? Availability::offTime($u->id, $start, $end ?: $start, DriveMode::WithDriver) : null;

                return [$u->id => $u->name.($u->phone ? ' · '.$u->phone : '').($clash ? self::BUSY.$clash->reference.' '.$clash->start_at->format('d M H:i') : '').($off ? ' · not available ('.($off->reason ?: 'off').')' : '')];
            })
            ->all();
    }
}
