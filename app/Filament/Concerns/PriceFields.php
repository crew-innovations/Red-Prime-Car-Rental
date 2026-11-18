<?php

namespace App\Filament\Concerns;

use App\Enums\ServiceType;
use App\Filament\Resources\BookingResource;
use App\Models\PackageDeparture;
use App\Support\Access;
use App\Support\Pricing\PriceEngine;
use App\Support\Tax;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Price section shared by quotations and bookings (28 Oct, Priority 2).
 * The numbers shown here come from App\Support\Pricing\PriceEngine and are
 * worked out again on the server when the record is saved.
 */
class PriceFields
{
    /** Delivery / return choice for cars (separate from self-drive or with driver). */
    public static function handoverChoice(bool $withFees = false): array
    {
        $isCar = fn (Get $get): bool => $get('service_type') === ServiceType::Car->value;

        return array_values(array_filter([
            Forms\Components\ToggleButtons::make('delivery_method')
                ->label('Customer gets the car')
                ->options(['office' => 'Collects from us', 'delivery' => 'We deliver'])
                ->icons(['office' => 'heroicon-m-building-storefront', 'delivery' => 'heroicon-m-truck'])
                ->default('office')
                ->grouped()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                ->visible($isCar)
                ->columnSpanFull(),
            ...($withFees ? self::feeSwitch('delivery', 'delivery_method') : []),
            Forms\Components\ToggleButtons::make('return_method')
                ->label('Car comes back')
                ->options(['office' => 'Customer returns it', 'collection' => 'We collect'])
                ->icons(['office' => 'heroicon-m-building-storefront', 'collection' => 'heroicon-m-truck'])
                ->default('office')
                ->grouped()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                ->visible($isCar)
                ->columnSpanFull(),
            ...($withFees ? self::feeSwitch('collection', 'return_method') : []),
        ]));
    }

    /**
     * 18 Nov (meeting item 2): the delivery / collection charge is not added by itself. Whoever may
     * set it (Admin by default) switches it on for this booking and can change the amount.
     */
    protected static function feeSwitch(string $kind, string $methodField): array
    {
        $shown = fn (Get $get): bool => $get('service_type') === ServiceType::Car->value && $get($methodField) === $kind;
        $may = fn (): bool => Access::allows('set-delivery-charges');
        $suggest = fn (Get $get): ?float => PriceEngine::suggestedHandoverFee($kind, BookingResource::findItem(ServiceType::Car->value, $get('bookable_id')));

        return [
            Forms\Components\Group::make()->columns(2)->columnSpanFull()->visible($shown)->schema([
                Forms\Components\Toggle::make($kind.'_fee_on')
                    ->label('Charge for '.$kind)
                    ->inline(false)
                    ->default(fn (): bool => \App\Models\Setting::read('delivery_fee_default', 'off') === 'price_list')
                    ->disabled(fn (): bool => ! $may())
                    ->helperText(fn (): ?string => $may() ? null : 'Admin switches this charge on and sets the amount.')
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $state) use ($kind, $suggest) {
                        if ($state && blank($get($kind.'_fee_amount'))) {
                            $set($kind.'_fee_amount', $suggest($get));
                        }
                        self::recalculate($get, $set);
                    }),
                Forms\Components\TextInput::make($kind.'_fee_amount')
                    ->label(ucfirst($kind).' charge')
                    ->numeric()->minValue(0)->prefix('AED')
                    ->disabled(fn (): bool => ! $may())
                    ->placeholder(fn (Get $get): string => ($a = $suggest($get)) !== null ? 'Price list: '.number_format($a, 2) : 'Type the amount')
                    ->helperText(fn (Get $get): ?string => ($a = $suggest($get)) !== null ? 'Price list AED '.number_format($a, 2).'. Change it for this booking if needed.' : null)
                    ->visible(fn (Get $get): bool => (bool) $get($kind.'_fee_on'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),
            ]),
        ];
    }

    /** 18 Nov (meeting item 4): security deposit or no deposit (with an optional no-deposit fee). */
    public static function depositChoice(): array
    {
        $isCar = fn (Get $get): bool => $get('service_type') === ServiceType::Car->value;
        $allowed = fn (): bool => (bool) \App\Models\Setting::read('no_deposit_allowed', true);

        return [
            Forms\Components\ToggleButtons::make('deposit_mode')
                ->label('Deposit')
                ->options(['security' => 'Security deposit', 'none' => 'No deposit'])
                ->icons(['security' => 'heroicon-m-shield-check', 'none' => 'heroicon-m-no-symbol'])
                ->default('security')
                ->grouped()
                ->live()
                ->disableOptionWhen(fn (string $value): bool => $value === 'none' && ! $allowed())
                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                    if ($state === 'none') {
                        $set('security_deposit', 0);
                    } elseif (! (float) $get('security_deposit') && ($item = BookingResource::findItem($get('service_type'), $get('bookable_id'))) && ($item->deposit ?? null)) {
                        $set('security_deposit', (float) $item->deposit);
                    }
                    self::recalculate($get, $set);
                })
                ->visible($isCar)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('no_deposit_fee')
                ->label('No-deposit fee')
                ->numeric()->minValue(0)->prefix('AED')
                ->placeholder(fn (Get $get): string => ($f = self::settingNoDepositFee($get)) > 0 ? 'From settings: '.number_format($f, 2) : 'No fee')
                ->helperText('Charged instead of holding a deposit. Empty = the amount from Contract settings (none until it is set).')
                ->disabled(fn (): bool => ! Access::allows('adjust-prices'))
                ->visible(fn (Get $get): bool => $isCar($get) && $get('deposit_mode') === 'none')
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),
        ];
    }

    protected static function settingNoDepositFee(Get $get): float
    {
        $r = PriceEngine::calculate(array_merge(self::input($get), ['no_deposit_fee' => null, 'deposit_mode' => 'none']));

        return $r ? PriceEngine::noDepositFee([], ['days' => $r['days'] ?? 1, 'base' => (float) ($r['base_amount'] ?? 0)]) : 0.0;
    }

    /** @param  string  $kind  quotation | booking */
    public static function schema(string $kind): array
    {
        $canAdjust = fn (): bool => Access::allows('adjust-prices');

        return [
            Forms\Components\Hidden::make('rate_type'),
            Forms\Components\Hidden::make('quantity'),
            Forms\Components\Hidden::make('rate'),
            Forms\Components\Hidden::make('standard_amount'),
            Forms\Components\Hidden::make('list_base')->dehydrated(false)
                ->afterStateHydrated(fn (Forms\Components\Hidden $component, ?Model $record) => $component->state($record?->price_breakdown['base_amount'] ?? null)),
            Forms\Components\Hidden::make('tax_rate')->default(fn () => Tax::defaultRate()),
            Forms\Components\Hidden::make('tax_inclusive')->default(fn () => Tax::defaultInclusive()),

            Forms\Components\CheckboxList::make('extra_keys')
                ->label('Extras')
                ->options(fn (Get $get): array => collect(PriceEngine::optionalExtras($get('service_type'), BookingResource::findItem($get('service_type'), $get('bookable_id'))))
                    ->map(fn (array $o) => $o['label'].' · AED '.number_format($o['price'], 2).' '.(PriceEngine::EXTRA_UNITS[$o['unit']] ?? ''))->all())
                ->afterStateHydrated(fn (Forms\Components\CheckboxList $component, ?Model $record) => $component->state($record?->chosenExtraKeys() ?? []))
                ->dehydrated(false)
                ->columns(2)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                ->visible(fn (Get $get): bool => PriceEngine::optionalExtras($get('service_type'), BookingResource::findItem($get('service_type'), $get('bookable_id'))) !== [])
                ->columnSpanFull(),

            Forms\Components\TextInput::make('base_amount')
                ->label(fn (Get $get): string => $get('service_type') === ServiceType::Car->value ? 'Rental price' : 'Price')
                ->numeric()->minValue(0)->prefix('AED')->default(0)->required()
                ->readOnly(fn (Get $get): bool => (! $canAdjust() && $get('standard_amount') !== null && $get('standard_amount') !== '') || filled($get('base_discount_percent')))
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set) => $set('base_discount_percent', null))
                ->helperText(fn (Get $get): ?HtmlString => self::baseHint($get))
                ->suffixAction(Forms\Components\Actions\Action::make('recalculate')
                    ->icon('heroicon-m-arrow-path')
                    ->tooltip('Back to the price list')
                    ->action(function (Get $get, Set $set) {
                        $set('base_discount_percent', null);
                        self::recalculate($get, $set, reset: true);
                    })),
            // 4 Nov (10): % off the rental line; follows the price list when the dates change.
            Forms\Components\TextInput::make('base_discount_percent')
                ->label('Discount on the price')
                ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                ->disabled(fn (): bool => ! $canAdjust())
                ->dehydrated()
                ->visible(fn (Get $get): bool => filled($get('list_base')))
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                    if (filled($get('list_base'))) {
                        $set('base_amount', \App\Support\Pricing\Discounts::apply((float) $get('list_base'), filled($state) ? (float) $state : null));
                    }
                    self::overallDiscount($get, $set);
                }),
            Forms\Components\Group::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\ToggleButtons::make('discount_mode')
                        ->label('Discount on the total')
                        ->options(['amount' => 'AED', 'percent' => '%'])
                        ->default('amount')
                        ->grouped()
                        ->disabled(fn (): bool => ! $canAdjust())
                        ->dehydrated()
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::overallDiscount($get, $set)),
                    Forms\Components\TextInput::make('discount_percent')
                        ->label('% off the total')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->disabled(fn (): bool => ! $canAdjust())
                        ->dehydrated()
                        ->visible(fn (Get $get): bool => $get('discount_mode') === 'percent')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::overallDiscount($get, $set)),
                    Forms\Components\TextInput::make('discount')
                        ->label('Amount off the total')
                        ->numeric()->minValue(0)->prefix('AED')->default(0)
                        ->disabled(fn (): bool => ! $canAdjust())
                        ->readOnly(fn (Get $get): bool => $get('discount_mode') === 'percent')
                        ->helperText(fn (Get $get): ?string => self::discountPercentText($get))
                        ->dehydrated()
                        ->live(onBlur: true),
                ]),

            Forms\Components\Repeater::make('extras')
                ->label('Delivery, fees and extras')
                ->schema([
                    Forms\Components\Hidden::make('key'),
                    Forms\Components\Hidden::make('kind'),
                    Forms\Components\Hidden::make('standard'),
                    Forms\Components\Hidden::make('unit'),
                    Forms\Components\Hidden::make('qty'),
                    Forms\Components\Hidden::make('unit_price'),
                    Forms\Components\TextInput::make('label')
                        ->required()->maxLength(120)
                        ->readOnly(fn (Get $get): bool => filled($get('key')))
                        ->datalist(fn (): array => \App\Support\Options::list('quote_extras'))
                        ->helperText(fn (Get $get): ?string => filled($get('key')) && $get('qty') && (float) $get('qty') != 1
                            ? PriceEngine::qtyText((float) $get('qty')).' × AED '.number_format((float) $get('unit_price'), 2)
                            : null)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('amount')
                        ->numeric()->minValue(0)->prefix('AED')->required()
                        ->readOnly(fn (Get $get): bool => (bool) $get('waived') || (filled($get('key')) && ! $canAdjust()) || filled($get('discount_percent')))
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set) => $set('discount_percent', null)),
                    // 4 Nov (10): % off a price-list line.
                    Forms\Components\TextInput::make('discount_percent')
                        ->label('Discount')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->visible(fn (Get $get): bool => filled($get('key')) && filled($get('standard')) && $canAdjust() && ! $get('waived'))
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('amount', \App\Support\Pricing\Discounts::apply((float) $get('standard'), filled($state) ? (float) $state : null))),
                    Forms\Components\Toggle::make('waived')
                        ->label('Waive')
                        ->inline(false)
                        ->visible(fn (Get $get): bool => filled($get('key')) && $canAdjust())
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('amount', $state ? 0 : (float) $get('standard'))),
                ])
                ->columns(5)
                ->defaultItems(0)
                ->addActionLabel('Add a custom line')
                // Price-list lines are removed by unticking the extra or waived; only custom lines are deleted.
                ->deleteAction(fn (Forms\Components\Actions\Action $action) => $action->hidden(
                    fn (array $arguments, Forms\Components\Repeater $component): bool => filled($component->getState()[$arguments['item'] ?? '']['key'] ?? null)
                ))
                ->reorderable(false)
                ->itemLabel(fn (array $state): ?string => ($state['label'] ?? null).(! empty($state['waived']) ? ' (waived)' : ''))
                ->live(onBlur: true)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('adjustment_reason')
                ->label('Why is the price different from the price list?')
                ->rows(2)
                ->required(fn (Get $get): bool => self::adjusted($get))
                ->visible(fn (Get $get): bool => self::adjusted($get) || filled($get('adjustment_reason')))
                ->helperText('Saved in the history with your name.')
                ->columnSpanFull(),

            Forms\Components\Placeholder::make('price_summary')
                ->label('Total')
                ->columnSpanFull()
                ->content(fn (Get $get): HtmlString => self::summary($get)),
        ];
    }

    /** Advance to confirm (4 Nov, 11): % of the total, default per service from Business settings. */
    public static function advance(string $kind): array
    {
        $min = fn (): ?float => \App\Support\Pricing\Advance::minPercent();
        $below = fn (Get $get): bool => filled($get('advance_percent')) && $min() !== null && (float) $get('advance_percent') < $min() - 0.001;

        return [
            Forms\Components\TextInput::make('advance_percent')
                ->label('Advance to confirm')
                ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                ->placeholder(fn (Get $get): string => ($d = \App\Support\Pricing\Advance::defaultPercent($get('service_type'))) !== null ? 'Default '.\App\Support\Pricing\Discounts::percentText($d) : 'None')
                ->helperText(function (Get $get) use ($kind): ?string {
                    $p = filled($get('advance_percent')) ? (float) $get('advance_percent') : null;
                    if ($p === null) {
                        return $kind === 'quotation' ? 'Empty: the default for the service is used on a new quotation, or type an amount below.' : 'Empty: the default for the service is used.';
                    }
                    $rate = filled($get('tax_rate')) ? (float) $get('tax_rate') : null;
                    [, $total] = Tax::apply(self::net($get), $rate, (bool) $get('tax_inclusive'));

                    return '= AED '.number_format(\App\Support\Pricing\Advance::amount($total, $p), 2).' of AED '.number_format($total, 2).'. The security deposit is separate.';
                })
                ->live(onBlur: true),
            Forms\Components\TextInput::make('advance_required')
                ->label('Advance amount')
                ->numeric()->minValue(0)->prefix('AED')
                ->helperText('Used only when no % is set.')
                ->visible(fn (Get $get): bool => $kind === 'quotation' && blank($get('advance_percent'))),
            Forms\Components\TextInput::make('advance_reason')
                ->label(fn (): string => 'Why below '.\App\Support\Pricing\Discounts::percentText($min()).'?')
                ->maxLength(255)
                ->required($below)
                ->visible($below),
        ];
    }

    /** Engine input from the form state. */
    public static function input(Get $get, ?array $keys = null): array
    {
        $service = $get('service_type');

        return [
            'service_type' => $service,
            'item' => BookingResource::findItem($service, $get('bookable_id')),
            'start_at' => $get('start_at'),
            'end_at' => $get('end_at'),
            'drive_mode' => $get('drive_mode'),
            'guests' => (int) $get('guests'),
            'departure' => $get('package_departure_id') ? PackageDeparture::find($get('package_departure_id')) : null,
            'delivery_method' => $service === ServiceType::Car->value ? $get('delivery_method') : null,
            'return_method' => $service === ServiceType::Car->value ? $get('return_method') : null,
            'extra_keys' => $keys ?? (array) ($get('extra_keys') ?? []),
            // limousine & transfers (28 Oct-2)
            'transfer_service_id' => $get('transfer_service_id'), 'vehicle_category_id' => $get('vehicle_category_id'),
            'journey_type' => $get('journey_type'), 'route_km' => $get('route_km'), 'return_km' => $get('return_route_km'),
            'route_minutes' => $get('route_minutes'), 'route_source' => $get('route_source'), 'stops' => array_values((array) ($get('stops') ?? [])),
            'waiting_minutes' => $get('waiting_minutes'), 'return_at' => $get('return_at'),
            'pickup_location' => $get('pickup_location'), 'dropoff_location' => $get('dropoff_location'),
        ] + (self::hasFeeSwitches($get) ? [
            // 18 Nov (meeting items 2 and 4): only the booking form has these fields
            'delivery_fee_on' => $get('delivery_fee_on'), 'delivery_fee_amount' => $get('delivery_fee_amount'),
            'collection_fee_on' => $get('collection_fee_on'), 'collection_fee_amount' => $get('collection_fee_amount'),
            'deposit_mode' => $get('deposit_mode'), 'no_deposit_fee' => $get('no_deposit_fee'),
        ] : []);
    }

    /** The booking form has the delivery / collection switches; the quotation form does not. */
    protected static function hasFeeSwitches(Get $get): bool
    {
        return $get('deposit_mode') !== null || $get('delivery_fee_on') !== null || $get('collection_fee_on') !== null;
    }

    /**
     * Recalculates after dates, car, extras... change. A price the agent
     * changed by hand is kept; "Back to the price list" resets everything.
     */
    public static function recalculate(Get $get, Set $set, bool $reset = false): void
    {
        $result = PriceEngine::calculate(self::input($get));
        if (! $result) {
            $set('standard_amount', null);

            return;
        }

        $listBase = $get('list_base');
        $current = $get('base_amount');
        $basePercent = filled($get('base_discount_percent')) ? (float) $get('base_discount_percent') : null;
        $keep = ! $reset && $listBase !== null && $listBase !== '' && $current !== null && $current !== ''
            && abs((float) $current - (float) $listBase) > 0.005;
        $set('rate_type', $result['rate_type']);
        $set('quantity', $result['quantity']);
        $set('rate', $result['rate']);
        $set('list_base', $result['base_amount']);
        if (! $keep) {
            $set('base_amount', $result['base_amount']);
        }
        if ($basePercent && ! $reset) {
            $set('base_amount', \App\Support\Pricing\Discounts::apply((float) $result['base_amount'], $basePercent));
        }

        $old = collect((array) ($get('extras') ?? []));
        $byKey = $old->filter(fn ($l) => filled($l['key'] ?? null))->keyBy('key');
        $lines = collect($result['lines'])->map(function (array $line) use ($byKey, $reset) {
            $was = $byKey->get($line['key']);
            if (! $was || $reset) {
                return $line;
            }
            $waived = (bool) ($was['waived'] ?? false);
            $changed = ! $waived && isset($was['standard']) && abs((float) ($was['amount'] ?? 0) - (float) $was['standard']) > 0.005;
            $percent = ! $waived && filled($was['discount_percent'] ?? null) ? (float) $was['discount_percent'] : null;

            return array_merge($line, ['waived' => $waived, 'discount_percent' => $percent,
                'amount' => $waived ? 0 : ($percent ? \App\Support\Pricing\Discounts::apply((float) $line['standard'], $percent) : ($changed ? (float) $was['amount'] : $line['standard']))]);
        });
        $manual = $old->filter(fn ($l) => blank($l['key'] ?? null))->values();
        $all = $lines->concat($manual)->values();
        $set('extras', $all->mapWithKeys(fn ($l) => [(string) \Illuminate\Support\Str::uuid() => $l])->all());
        $set('standard_amount', round($result['base_amount'] + $lines->sum('standard') + $manual->sum(fn ($l) => (float) ($l['amount'] ?? 0)), 2));
        if ($result['deposit'] > 0 && ! (float) $get('security_deposit')) {
            $set('security_deposit', $result['deposit']);
        }
        self::overallDiscount($get, $set);
    }

    /** Discount on the total as a % (4 Nov): the AED amount follows rental + extras. */
    public static function overallDiscount(Get $get, Set $set): void
    {
        if ($get('discount_mode') !== 'percent') {
            return;
        }
        $extras = collect((array) ($get('extras') ?? []))->sum(fn ($l) => (float) ($l['amount'] ?? 0));
        $gross = (float) $get('base_amount') + $extras;
        $set('discount', round($gross * min(100, max(0, (float) $get('discount_percent'))) / 100, 2));
    }

    /** The overall discount as a %, shown even when an amount was typed. */
    protected static function discountPercentText(Get $get): ?string
    {
        $extras = collect((array) ($get('extras') ?? []))->sum(fn ($l) => (float) ($l['amount'] ?? 0));
        $gross = (float) $get('base_amount') + $extras;
        $d = (float) $get('discount');

        return $d > 0 && $gross > 0 ? '= '.\App\Support\Pricing\Discounts::percentText(round($d / $gross * 100, 2)).' of AED '.number_format($gross, 2) : null;
    }

    public static function adjusted(Get $get): bool
    {
        $standard = $get('standard_amount');
        if ($standard === null || $standard === '') {
            return (float) $get('discount') > 0;
        }

        return abs(self::net($get) - (float) $standard) > 0.005;
    }

    protected static function net(Get $get): float
    {
        $extras = collect((array) ($get('extras') ?? []))->sum(fn ($l) => (float) ($l['amount'] ?? 0));

        return round((float) $get('base_amount') + $extras - (float) $get('discount'), 2);
    }

    protected static function baseHint(Get $get): ?HtmlString
    {
        $input = self::input($get);
        if (! $input['item'] || (! $input['start_at'] && ! $input['item'] instanceof \App\Models\Package)) {
            return null;
        }
        $result = PriceEngine::calculate($input);
        if (! $result) {
            return new HtmlString('<span style="opacity:.75">No rate on the price list for this. Type the price.</span>');
        }
        $parts = [e($result['base_label'])];
        if ($result['rule']) {
            $parts[] = '<span style="color:rgb(var(--warning-600))">'.e($result['rule']['name']).' ('.e($result['rule']['effect']).')</span>';
        }
        foreach (array_merge($result['notes'], $result['warnings']) as $note) {
            $parts[] = '<span style="color:rgb(var(--warning-600))">'.e($note).'</span>';
        }

        return new HtmlString(implode('<br>', $parts));
    }

    protected static function summary(Get $get): HtmlString
    {
        $rate = filled($get('tax_rate')) ? (float) $get('tax_rate') : null;
        $net = self::net($get);
        [$tax, $total] = Tax::apply($net, $rate, (bool) $get('tax_inclusive'));
        $html = self::discountTable($get);
        $html .= '<span style="font-size:1.4rem;font-weight:700">AED '.number_format($total, 2).'</span>';
        if ($rate) {
            $html .= '<div style="font-size:.85rem;opacity:.75">VAT '.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%'.($get('tax_inclusive') ? ' included' : '').': AED '.number_format($tax, 2).'</div>';
        }
        $standard = $get('standard_amount');
        if ($standard !== null && $standard !== '' && abs($net - (float) $standard) > 0.005) {
            [, $listTotal] = Tax::apply((float) $standard, $rate, (bool) $get('tax_inclusive'));
            $diff = $total - $listTotal;
            $off = \App\Support\Pricing\Discounts::percentOff((float) $standard, $net);
            $html .= '<div style="font-size:.85rem;margin-top:4px">Price list: <s>AED '.number_format($listTotal, 2).'</s> · '
                .'<span style="color:rgb(var(--'.($diff < 0 ? 'danger' : 'success').'-600));font-weight:600">'.($diff < 0 ? '−' : '+').'AED '.number_format(abs($diff), 2)
                .($off > 0 ? ' ('.\App\Support\Pricing\Discounts::percentText($off).' off)' : '').'</span></div>';
            if ($off > 0 && auth()->user()) {
                [$max, $waits] = \App\Support\Pricing\Discounts::limitFor(auth()->user());
                if ($max !== null && $off > $max + 0.001) {
                    $html .= '<div style="font-size:.85rem;margin-top:4px;color:rgb(var(--warning-600));font-weight:600">'
                        .($waits ? 'Above your limit of '.\App\Support\Pricing\Discounts::percentText($max).': a manager must approve it before it can be sent or confirmed.'
                            : 'Above your limit of '.\App\Support\Pricing\Discounts::percentText($max).'.').'</div>';
                }
            }
        }

        return new HtmlString($html);
    }

    /** List price → discount % → discount amount → net, per line (4 Nov, 10). Only when something is discounted. */
    protected static function discountTable(Get $get): string
    {
        $rows = [];
        $listBase = $get('list_base');
        if (filled($listBase) && (float) $listBase > 0) {
            $rows[] = [$get('service_type') === ServiceType::Car->value ? 'Rental' : 'Price', (float) $listBase, (float) $get('base_amount')];
        }
        foreach ((array) ($get('extras') ?? []) as $l) {
            if (filled($l['standard'] ?? null)) {
                $rows[] = [(string) ($l['label'] ?? 'Extra'), (float) $l['standard'], (float) ($l['amount'] ?? 0)];
            }
        }
        $lineOff = collect($rows)->contains(fn ($r) => $r[1] - $r[2] > 0.005);
        $overall = (float) $get('discount');
        if (! $lineOff && $overall <= 0) {
            return '';
        }

        $td = 'style="padding:2px 8px;text-align:right;white-space:nowrap"';
        $html = '<table style="font-size:.8rem;margin-bottom:8px;border-collapse:collapse"><tr style="opacity:.65"><td style="padding:2px 8px 2px 0"></td><td '.$td.'>List</td><td '.$td.'>Discount</td><td '.$td.'>AED off</td><td '.$td.'>Net</td></tr>';
        foreach ($rows as [$label, $list, $amount]) {
            $off = round($list - $amount, 2);
            $html .= '<tr><td style="padding:2px 8px 2px 0">'.e(\Illuminate\Support\Str::limit($label, 40)).'</td><td '.$td.'>'.number_format($list, 2).'</td>'
                .'<td '.$td.'>'.($off > 0.005 ? \App\Support\Pricing\Discounts::percentText(\App\Support\Pricing\Discounts::percentOff($list, $amount)) : '–').'</td>'
                .'<td '.$td.'>'.($off > 0.005 ? number_format($off, 2) : '–').'</td><td '.$td.'>'.number_format($amount, 2).'</td></tr>';
        }
        if ($overall > 0) {
            $gross = (float) $get('base_amount') + collect((array) ($get('extras') ?? []))->sum(fn ($l) => (float) ($l['amount'] ?? 0));
            $html .= '<tr><td style="padding:2px 8px 2px 0">Discount on the total</td><td '.$td.'>'.number_format($gross, 2).'</td>'
                .'<td '.$td.'>'.($gross > 0 ? \App\Support\Pricing\Discounts::percentText(round($overall / $gross * 100, 2)) : '–').'</td><td '.$td.'>'.number_format($overall, 2).'</td><td '.$td.'>'.number_format($gross - $overall, 2).'</td></tr>';
        }

        return $html.'</table>';
    }
}
