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
                'delivery_fuel' => 'full',
                'driver_id' => $record->driver_id,
                'deposit_received' => max(0, (float) $record->security_deposit - $record->depositHeld()) ?: null,
                'deposit_method' => PaymentMethod::Card->value,
                'payment_amount' => (float) $record->balance ?: null,
                'payment_method' => PaymentMethod::Card->value,
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
                    Forms\Components\Select::make('delivery_fuel')
                        ->label('Fuel level')
                        ->options(Booking::FUEL_LEVELS)
                        ->native(false)
                        ->visible($record->isCar()),
                    Forms\Components\Select::make('driver_id')
                        ->label('Driver')
                        ->options(fn () => self::driverOptions())
                        ->required()
                        ->visible($record->drive_mode === DriveMode::WithDriver)
                        ->columnSpanFull(),
                ]),
                Forms\Components\Fieldset::make('Money received now')
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
                    ->label('Handover notes')
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
            ->modalDescription(fn (Booking $record): string => $record->customer?->name.' · due back '.$record->end_at->format('d M, H:i'))
            ->modalSubmitActionLabel(fn (Booking $record): string => $record->isCar() ? 'Complete return' : 'Complete '.$record->serviceNoun())
            ->modalWidth('4xl')
            ->authorize('operate-fleet')
            ->visible(fn (Booking $record): bool => $record->status === BookingStatus::InProgress && ! $record->trashed())
            ->fillForm(function (Booking $record): array {
                return [
                    'returned_at' => now(),
                    'return_fuel' => $record->delivery_fuel ?? 'full',
                    'return_condition' => 'good',
                    'after_return' => 'ready',
                    'charges' => array_filter(['late' => \App\Support\Rules::lateCharge($record, now())]),
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
                    Forms\Components\Select::make('return_fuel')
                        ->label('Fuel level')
                        ->options(Booking::FUEL_LEVELS)
                        ->native(false)
                        ->helperText('Handed over: '.(Booking::FUEL_LEVELS[$record->delivery_fuel] ?? '-'))
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
                        ->content('Extra km and late return are filled in as estimates from your rules. Check or change them: they are only charged when you complete the return.'),
                        ...collect(\App\Support\Options::charges())->map(fn ($meta, $key) => Forms\Components\TextInput::make("charges.{$key}")
                        ->label($meta[0])
                        ->numeric()->minValue(0)->prefix('AED')
                        ->live(onBlur: true)
                        ->visible($record->isCar() || ! $meta[2])
                    )->values()->all()]),

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
                        Forms\Components\TextInput::make('payment_amount')
                            ->label('Payment received now')
                            ->numeric()->minValue(0)->prefix('AED'),
                        Forms\Components\Select::make('payment_method')
                            ->label('Paid by')
                            ->options(PaymentMethod::class)->native(false),
                        Forms\Components\Toggle::make('deduct_from_deposit')
                            ->label('Take what is still owed from the deposit')
                            ->visible($record->depositHeld() > 0 && Access::allows('manage-finance')),
                        Forms\Components\Toggle::make('refund_deposit')
                            ->label('Refund the rest of the deposit now')
                            ->helperText('Leave off to hold it for Salik / fines and refund later.')
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

    public static function cancel(string $class)
    {
        return $class::make('cancelBooking') // not 'cancel': that name is taken by the form's Cancel button
            ->label('Cancel booking')
            ->authorize('manage-bookings')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalWidth('lg')
            ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Inquiry, BookingStatus::Quotation, BookingStatus::PendingPayment, BookingStatus::Confirmed], true))
            ->form([
                Forms\Components\ToggleButtons::make('status')
                    ->hiddenLabel()
                    ->options([BookingStatus::Cancelled->value => 'Cancelled', BookingStatus::NoShow->value => 'No-show'])
                    ->colors([BookingStatus::Cancelled->value => 'danger', BookingStatus::NoShow->value => 'danger'])
                    ->default(BookingStatus::Cancelled->value)
                    ->inline()
                    ->required(),
                Forms\Components\TextInput::make('cancel_reason')
                    ->label('Reason')
                    ->datalist(\App\Support\Options::list('cancel_reasons'))
                    ->required(),
            ])
            ->modalDescription(fn (Booking $record): string => (float) $record->paid_amount > 0
                ? self::aed($record->paid_amount).' was already received. Record any refund in Accounts.'
                : 'The car / yacht will be free for these dates again.')
            ->action(function (Booking $record, array $data): void {
                $record->update(['status' => $data['status'], 'cancel_reason' => $data['cancel_reason']]);

                Notification::make()->title('Booking '.strtolower(BookingStatus::from($data['status'])->getLabel()))->success()->send();
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
    public static function handoverBlockers(Booking $record): array
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

        return $blockers;
    }

    /** Active users with the Driver role. */
    public static function driverOptions(?int $carId = null): array
    {
        $usual = $carId ? Car::find($carId)?->drivers()->pluck('users.id')->all() ?? [] : [];

        return User::role('Driver')->where('is_active', true)->orderBy('name')->get()
            ->sortBy(fn (User $u) => in_array($u->id, $usual, true) ? 0 : 1, SORT_REGULAR)
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name.(in_array($u->id, $usual, true) ? ' ★ usual driver' : '').($u->phone ? ' · '.$u->phone : '')])
            ->all();
    }
}
