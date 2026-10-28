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
    public static function handoverChoice(): array
    {
        $isCar = fn (Get $get): bool => $get('service_type') === ServiceType::Car->value;

        return [
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
        ];
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
                ->readOnly(fn (Get $get): bool => ! $canAdjust() && $get('standard_amount') !== null && $get('standard_amount') !== '')
                ->live(onBlur: true)
                ->helperText(fn (Get $get): ?HtmlString => self::baseHint($get))
                ->suffixAction(Forms\Components\Actions\Action::make('recalculate')
                    ->icon('heroicon-m-arrow-path')
                    ->tooltip('Back to the price list')
                    ->action(fn (Get $get, Set $set) => self::recalculate($get, $set, reset: true))),
            Forms\Components\TextInput::make('discount')
                ->numeric()->minValue(0)->prefix('AED')->default(0)
                ->disabled(fn (): bool => ! $canAdjust())
                ->dehydrated()
                ->live(onBlur: true),

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
                        ->readOnly(fn (Get $get): bool => (bool) $get('waived') || (filled($get('key')) && ! $canAdjust()))
                        ->live(onBlur: true),
                    Forms\Components\Toggle::make('waived')
                        ->label('Waive')
                        ->inline(false)
                        ->visible(fn (Get $get): bool => filled($get('key')) && $canAdjust())
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('amount', $state ? 0 : (float) $get('standard'))),
                ])
                ->columns(4)
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
        ];
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
        $keep = ! $reset && $listBase !== null && $listBase !== '' && $current !== null && $current !== ''
            && abs((float) $current - (float) $listBase) > 0.005;
        $set('rate_type', $result['rate_type']);
        $set('quantity', $result['quantity']);
        $set('rate', $result['rate']);
        $set('list_base', $result['base_amount']);
        if (! $keep) {
            $set('base_amount', $result['base_amount']);
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

            return array_merge($line, ['waived' => $waived, 'amount' => $waived ? 0 : ($changed ? (float) $was['amount'] : $line['standard'])]);
        });
        $manual = $old->filter(fn ($l) => blank($l['key'] ?? null))->values();
        $all = $lines->concat($manual)->values();
        $set('extras', $all->mapWithKeys(fn ($l) => [(string) \Illuminate\Support\Str::uuid() => $l])->all());
        $set('standard_amount', round($result['base_amount'] + $lines->sum('standard') + $manual->sum(fn ($l) => (float) ($l['amount'] ?? 0)), 2));
        if ($result['deposit'] > 0 && ! (float) $get('security_deposit')) {
            $set('security_deposit', $result['deposit']);
        }
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
        $html = '<span style="font-size:1.4rem;font-weight:700">AED '.number_format($total, 2).'</span>';
        if ($rate) {
            $html .= '<div style="font-size:.85rem;opacity:.75">VAT '.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%'.($get('tax_inclusive') ? ' included' : '').': AED '.number_format($tax, 2).'</div>';
        }
        $standard = $get('standard_amount');
        if ($standard !== null && $standard !== '' && abs($net - (float) $standard) > 0.005) {
            [, $listTotal] = Tax::apply((float) $standard, $rate, (bool) $get('tax_inclusive'));
            $diff = $total - $listTotal;
            $html .= '<div style="font-size:.85rem;margin-top:4px">Price list: <s>AED '.number_format($listTotal, 2).'</s> · '
                .'<span style="color:rgb(var(--'.($diff < 0 ? 'danger' : 'success').'-600));font-weight:600">'.($diff < 0 ? '−' : '+').'AED '.number_format(abs($diff), 2).'</span></div>';
        }

        return new HtmlString($html);
    }
}
