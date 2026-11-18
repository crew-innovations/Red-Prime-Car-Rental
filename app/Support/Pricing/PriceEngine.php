<?php

namespace App\Support\Pricing;

use App\Enums\DriveMode;
use App\Models\Car;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\PriceExtra;
use App\Models\PriceRule;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\Yacht;
use App\Support\Rules;
use App\Support\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The one place prices are worked out (28 Oct brief, Priority 2.2). Used by
 * quotations, bookings, booking changes, the price check page and the website
 * API. The browser only shows what this returns; quotations and bookings run
 * it again on the server when they are saved.
 *
 * Standard rates live on each car / yacht / trip / package. Special rates
 * (PriceRule) can replace or move a rate for a season, special dates,
 * weekdays or a whole category. Seasonal rates are chosen by the start date.
 *
 * Returns null when there is nothing to price (no item, no dates, no rate).
 */
class PriceEngine
{
    /** Units used for add-ons and fees, and how many of them a booking has. */
    public const EXTRA_UNITS = [
        'per_booking' => 'per booking', 'per_rental' => 'per rental', 'per_day' => 'per day',
        'per_hour' => 'per hour', 'per_guest' => 'per guest', 'per_person' => 'per person',
    ];

    /**
     * @param  array{service_type?: ?string, item?: ?Model, start_at?: mixed, end_at?: mixed, drive_mode?: ?string,
     *               guests?: ?int, departure?: ?PackageDeparture, delivery_method?: ?string, return_method?: ?string,
     *               extra_keys?: array<int, string>}  $in
     */
    public static function calculate(array $in): ?array
    {
        $service = self::value($in['service_type'] ?? null);
        $item = $in['item'] ?? null;
        $guests = max(0, (int) ($in['guests'] ?? 0));
        $mode = self::value($in['drive_mode'] ?? null);
        $start = filled($in['start_at'] ?? null) ? Carbon::parse($in['start_at']) : null;
        $end = filled($in['end_at'] ?? null) ? Carbon::parse($in['end_at']) : null;
        $departure = $in['departure'] ?? null;

        // Limousine & transfers are priced by vehicle category and route (28 Oct-2).
        if ($service === 'transfer') {
            return TransferPricing::calculate($in);
        }
        if (! $item || ! $service) {
            return null;
        }
        if (! $item instanceof Package && ! $start) {
            return null;
        }
        $end ??= $start?->copy();

        $notes = [];
        $warnings = [];
        $span = $start ? self::span($start, $end) : ['minutes' => 0, 'hours' => 0, 'billed_hours' => 0, 'days' => 1];
        $rules = self::rulesFor($service, $item, $mode, $start ?? $departure?->startsAt() ?? now());

        $base = match (true) {
            $item instanceof Car => self::carBase($item, $span, $mode, $rules, $notes, $warnings),
            $item instanceof Yacht => self::yachtBase($item, $span, $rules, $notes, $warnings, $guests),
            $item instanceof Trip => self::tripBase($item, $guests, $rules, $notes),
            $item instanceof Package => self::packageBase($item, $guests, $departure, $rules, $notes),
            default => null,
        };
        if (! $base) {
            return null;
        }

        $context = ['days' => $span['days'], 'hours' => $span['hours'], 'guests' => max(1, $guests), 'base' => $base['amount']];
        $lines = self::extraLines($service, $item, $in, $context, $warnings);
        $extras = round($lines->sum('amount'), 2);

        return [
            'service_type' => $service,
            'item' => ['type' => $item->getMorphClass(), 'id' => $item->getKey(), 'name' => $item->name ?? null],
            'start_at' => $start?->toDateTimeString(),
            'end_at' => $end?->toDateTimeString(),
            'drive_mode' => $mode,
            'guests' => $guests ?: null,
            'hours' => $span['hours'],
            'days' => $span['days'],
            'rate_type' => $base['unit'],
            'quantity' => $base['qty'],
            'rate' => $base['rate'],
            'standard_rate' => $base['standard'],
            'base_amount' => $base['amount'],
            'base_label' => self::qtyText($base['qty']).' × AED '.number_format($base['rate'], 2).' / '.self::unitLabel($base['unit']),
            'rule' => $base['rule'] ? ['id' => $base['rule']->id, 'name' => $base['rule']->name, 'effect' => $base['rule']->effectText()] : null,
            'lines' => $lines->values()->all(),
            'extras_amount' => $extras,
            'standard_amount' => round($base['amount'] + $extras, 2),
            'deposit' => ($in['deposit_mode'] ?? null) === 'none' ? 0.0 : self::deposit($item),
            'notes' => $notes,
            'warnings' => $warnings,
            'calculated_at' => now()->toDateTimeString(),
        ];
    }

    /** Totals with VAT, a discount and any waived lines. */
    public static function totals(float $base, array $lines, float $discount, ?float $taxRate, bool $inclusive): array
    {
        $extras = round(collect($lines)->sum(fn ($l) => (float) ($l['amount'] ?? 0)), 2);
        $net = $base + $extras - $discount;
        [$tax, $total] = Tax::apply($net, $taxRate, $inclusive);

        return ['extras' => $extras, 'net' => round(max(0, $net), 2), 'tax' => $tax, 'total' => $total];
    }

    /**
     * Extras a customer can choose for this item: its own add-ons plus the
     * catalogue. key => ['label', 'unit', 'price'].
     */
    public static function optionalExtras(?string $service, ?Model $item): array
    {
        $out = [];
        foreach ((array) ($item?->addons ?? []) as $addon) {
            if (blank($addon['name'] ?? null)) {
                continue;
            }
            $out['addon:'.Str::slug($addon['name'])] = ['label' => $addon['name'], 'unit' => $addon['unit'] ?? 'per_booking', 'price' => (float) ($addon['price'] ?? 0)];
        }
        if ($service) {
            foreach (PriceExtra::query()->active()->where('kind', 'extra')->orderBy('sort_order')->orderBy('name')->get() as $extra) {
                if ($extra->appliesTo($service)) {
                    $out['extra:'.$extra->id] = ['label' => $extra->name, 'unit' => $extra->unit, 'price' => (float) $extra->amount];
                }
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */

    protected static function span(Carbon $start, Carbon $end): array
    {
        $minutes = max(1, (int) $start->diffInMinutes($end));
        $step = max(1, (int) Setting::read('hourly_increment_minutes', 60));
        $hours = max(1, (int) ceil($minutes / 60));

        return [
            'minutes' => $minutes,
            'hours' => $hours,
            'billed_hours' => self::whole(round(ceil($minutes / $step) * $step / 60, 2)), // hourly rates bill in these steps
            'days' => max(1, (int) ceil($hours / 24)),
        ];
    }

    /** Special rates that could apply to this item on the start date, best first. */
    protected static function rulesFor(string $service, Model $item, ?string $mode, Carbon $on): Collection
    {
        $category = $item->type ?? null;

        return PriceRule::query()->active()->where('service_type', $service)->get()
            ->filter(fn (PriceRule $r) => match ($r->applies_to) {
                'item' => (int) $r->item_id === (int) $item->getKey(),
                'category' => $category && strcasecmp((string) $r->category, (string) $category) === 0,
                default => true,
            })
            ->filter(fn (PriceRule $r) => ! $r->drive_mode || $r->drive_mode === 'any' || $r->drive_mode === $mode)
            ->filter(fn (PriceRule $r) => $r->appliesOn($on))
            ->sortBy([['priority', 'desc'], [fn (PriceRule $r) => $r->specificity(), 'desc'], ['id', 'desc']])
            ->values();
    }

    /**
     * Rate for one unit: the best special rate that can give one, else the
     * standard rate. Returns [rate, rule|null, standard] or null.
     */
    protected static function rateFor(string $unit, ?float $standard, Collection $rules): ?array
    {
        $standard = $standard !== null && $standard > 0 ? $standard : null;

        foreach ($rules as $rule) {
            if ($rule->method === 'set' && $rule->unit === $unit) {
                return [round((float) $rule->amount, 2), $rule, $standard];
            }
            if ($rule->method === 'percent' && ($rule->unit === $unit || $rule->unit === 'any') && $standard !== null) {
                return [round(max(0, $standard * (1 + (float) $rule->amount / 100)), 2), $rule, $standard];
            }
        }

        return $standard !== null ? [$standard, null, $standard] : null;
    }

    /**
     * A special rate can say "charge at least N units" (min_units). Minimums
     * saved on a car / yacht are only warned about, never charged silently.
     */
    protected static function result(string $unit, int|float $qty, array $rate, array &$notes = []): array
    {
        [$amount, $rule, $standard] = $rate;
        $min = (float) ($rule?->min_units ?? 0);
        if ($min > 0 && $qty < $min) {
            $notes[] = 'Charged for at least '.self::qtyText($min).' '.self::unitLabel($unit, $min).' ('.$rule->name.').';
            $qty = self::whole($min);
        }

        return ['unit' => $unit, 'qty' => $qty, 'rate' => $amount, 'standard' => $standard, 'amount' => round($qty * $amount, 2), 'rule' => $rule];
    }

    protected static function carBase(Car $car, array $span, ?string $mode, Collection $rules, array &$notes, array &$warnings): ?array
    {
        $hours = $span['hours'];
        $days = $span['days'];
        $billed = $span['billed_hours'];

        if ($mode === DriveMode::WithDriver->value) {
            $hourly = self::rateFor('hour', self::num($car->chauffeur_hourly_price), $rules);
            if ($hours < 24 && $hourly && (! $car->driver_hours_per_day || $hours < $car->driver_hours_per_day)) {
                return self::result('hour', $billed, $hourly, $notes);
            }
            $daily = self::rateFor('day', self::num($car->chauffeur_daily_price), $rules);

            return $daily ? self::result('day', $days, $daily, $notes) : null;
        }

        $hourly = self::rateFor('hour', self::num($car->hourly_price), $rules);
        if ($hours < 24 && $hourly && $hours < Rules::pricing('hourly_max_hours')) {
            return self::result('hour', $billed, $hourly, $notes);
        }
        if ($days >= Rules::pricing('monthly_from_days') && ($monthly = self::rateFor('month', self::num($car->monthly_price), $rules))) {
            return self::result('month', round($days / 30, 2), $monthly, $notes);
        }
        if ($days >= Rules::pricing('weekly_from_days') && ($weekly = self::rateFor('week', self::num($car->weekly_price), $rules))) {
            return self::result('week', round($days / 7, 2), $weekly, $notes);
        }
        $daily = self::rateFor('day', self::num($car->daily_price), $rules);
        if ($daily && $car->min_rental_days > 1 && $days < $car->min_rental_days) {
            $warnings[] = "Shorter than this car's minimum rental ({$car->min_rental_days} days).";
        }

        return $daily ? self::result('day', $days, $daily, $notes) : null;
    }

    protected static function yachtBase(Yacht $yacht, array $span, Collection $rules, array &$notes, array &$warnings, int $guests): ?array
    {
        $hours = $span['hours'];
        if ($yacht->max_guests && $guests > $yacht->max_guests) {
            $warnings[] = "This yacht takes up to {$yacht->max_guests} guests.";
        }

        if ($hours >= Rules::pricing('yacht_full_day_from_hours') && ($full = self::rateFor('full_day', self::num($yacht->full_day_price), $rules))) {
            return self::result('full_day', max(1, (int) ceil($hours / 24)), $full, $notes);
        }
        if ($hours === Rules::pricing('yacht_half_day_hours') && ($half = self::rateFor('half_day', self::num($yacht->half_day_price), $rules))) {
            return self::result('half_day', 1, $half, $notes);
        }
        $hourly = self::rateFor('hour', self::num($yacht->hourly_price), $rules);
        if ($hourly && $yacht->minimum_hours && $hours < $yacht->minimum_hours) {
            $warnings[] = "Shorter than this yacht's minimum charter ({$yacht->minimum_hours} hours).";
        }

        return $hourly ? self::result('hour', $span['billed_hours'], $hourly, $notes) : null;
    }

    protected static function tripBase(Trip $trip, int $guests, Collection $rules, array &$notes): ?array
    {
        if ($trip->price_unit === 'per_trip') {
            $rate = self::rateFor('trip', self::num($trip->price), $rules);

            return $rate ? self::result('trip', 1, $rate, $notes) : null;
        }
        $rate = self::rateFor('guest', self::num($trip->price), $rules);

        return $rate ? self::result('guest', (float) max(1, $guests), $rate, $notes) : null;
    }

    protected static function packageBase(Package $package, int $guests, ?PackageDeparture $departure, Collection $rules, array &$notes): ?array
    {
        $price = $package->priceFor($guests, $departure);
        if (! $price) {
            return null;
        }
        [$unit, $qty, $rate] = $price;
        $applied = self::rateFor($unit, (float) $rate, $rules);

        return $applied ? self::result($unit, (float) $qty, $applied, $notes) : null;
    }

    /** Delivery, collection, always-added fees and the extras chosen. */
    protected static function extraLines(string $service, Model $item, array $in, array $ctx, array &$warnings): Collection
    {
        $lines = collect();
        $catalogue = PriceExtra::query()->active()->orderBy('sort_order')->orderBy('name')->get()->filter(fn (PriceExtra $e) => $e->appliesTo($service));

        // Always-added charges saved on the yacht / trip (marina fee...), then catalogue fees.
        foreach ((array) ($item->additional_charges ?? []) as $charge) {
            if (blank($charge['name'] ?? null)) {
                continue;
            }
            $unit = $charge['unit'] ?? 'per_booking';
            $amount = $unit === 'percent'
                ? round($ctx['base'] * (float) ($charge['amount'] ?? 0) / 100, 2)
                : self::extraAmount((float) ($charge['amount'] ?? 0), $unit, $ctx);
            $lines->push(self::line('charge:'.Str::slug($charge['name']), $charge['name'], 'fee', $unit, $amount, (float) ($charge['amount'] ?? 0), $ctx));
        }
        foreach ($catalogue->where('kind', 'fee') as $fee) {
            $lines->push(self::line('extra:'.$fee->id, $fee->name, 'fee', $fee->unit, self::extraAmount((float) $fee->amount, $fee->unit, $ctx), (float) $fee->amount, $ctx));
        }

        // 18 Nov (meeting item 2): delivery and collection charges are switched on per booking and the
        // amount can be changed there. A typed amount is the agreed charge (not a discount). With the
        // switch left empty, Business settings decides (off by default; "price list" = as before 18 Nov).
        foreach (['delivery' => 'delivery_method', 'collection' => 'return_method'] as $kind => $methodKey) {
            if (($in[$methodKey] ?? null) !== $kind) {
                continue;
            }
            // Quotations (no switch yet) keep the price-list charge as before.
            $on = array_key_exists($kind.'_fee_on', $in) ? $in[$kind.'_fee_on'] : true;
            $on = $on === null || $on === '' ? \App\Models\Setting::read('delivery_fee_default', 'off') === 'price_list' : filter_var($on, FILTER_VALIDATE_BOOL);
            if ($kind === 'delivery' && $item instanceof Car && ! $item->delivery_available) {
                $warnings[] = 'Delivery is switched off for this car.';
            }
            if (! $on) {
                continue;
            }
            $typed = self::num($in[$kind.'_fee_amount'] ?? null);
            $listed = self::listedHandoverFee($kind, $item, $catalogue, $ctx);
            if ($typed !== null) {
                $lines->push(self::line($kind, ucfirst($kind), $kind, 'per_booking', $typed, $typed, $ctx));
            } elseif ($listed) {
                $lines->push($listed);
            } else {
                $warnings[] = 'No '.$kind.' fee on the price list. Type the '.$kind.' charge on the booking.';
            }
        }

        // 18 Nov (meeting item 4): "No deposit" can carry a fee instead of the security deposit.
        if (($in['deposit_mode'] ?? null) === 'none' && ($fee = self::noDepositFee($in, $ctx)) > 0) {
            $lines->push(self::line('no_deposit_fee', 'No-deposit fee', 'fee', 'per_booking', $fee, $fee, $ctx));
        }

        $options = self::optionalExtras($service, $item);
        foreach (array_unique((array) ($in['extra_keys'] ?? [])) as $key) {
            if (! isset($options[$key]) || $lines->contains('key', $key)) {
                continue;
            }
            $o = $options[$key];
            $lines->push(self::line($key, $o['label'], 'extra', $o['unit'], self::extraAmount($o['price'], $o['unit'], $ctx), $o['price'], $ctx));
        }

        return $lines;
    }

    /** The delivery / collection fee from the price list (car, then Extras & fees), or null. */
    protected static function listedHandoverFee(string $kind, Model $item, Collection $catalogue, array $ctx): ?array
    {
        if ($kind === 'delivery' && $item instanceof Car && $item->delivery_fee !== null && (float) $item->delivery_fee > 0) {
            return self::line('delivery', 'Delivery', 'delivery', 'per_booking', (float) $item->delivery_fee, (float) $item->delivery_fee, $ctx);
        }
        if ($fee = $catalogue->firstWhere('kind', $kind)) {
            return self::line('extra:'.$fee->id, $fee->name, $kind, $fee->unit, self::extraAmount((float) $fee->amount, $fee->unit, $ctx), (float) $fee->amount, $ctx);
        }

        return null;
    }

    /** The price-list amount for a delivery / collection charge, to fill in when it is switched on. */
    public static function suggestedHandoverFee(string $kind, ?Model $item, ?string $service = 'car'): ?float
    {
        if (! $item) {
            return null;
        }
        $catalogue = PriceExtra::query()->active()->orderBy('sort_order')->orderBy('name')->get()->filter(fn (PriceExtra $e) => $e->appliesTo($service ?? 'car'));
        $line = self::listedHandoverFee($kind, $item, $catalogue, ['days' => 1, 'hours' => 0, 'guests' => 1, 'base' => 0]);

        return $line ? (float) $line['amount'] : null;
    }

    /** No-deposit fee: typed on the booking, else Business settings (off / fixed / per day / % of the rental). */
    public static function noDepositFee(array $in, array $ctx): float
    {
        $typed = self::num($in['no_deposit_fee'] ?? null);
        if ($typed !== null) {
            return round(max(0, $typed), 2);
        }
        $amount = (float) \App\Models\Setting::read('no_deposit_fee_amount');
        if ($amount <= 0) {
            return 0.0;
        }

        return match (\App\Models\Setting::read('no_deposit_fee_mode', 'off')) {
            'fixed' => round($amount, 2),
            'per_day' => round($amount * max(1, (int) ($ctx['days'] ?? 1)), 2),
            'percent' => round((float) ($ctx['base'] ?? 0) * $amount / 100, 2),
            default => 0.0,
        };
    }

    protected static function line(string $key, string $label, string $kind, string $unit, float $amount, float $price, array $ctx): array
    {
        $qty = self::extraQty($unit, $ctx);

        return [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'unit' => $unit,
            'unit_price' => round($price, 2),
            'qty' => $qty,
            'amount' => round($amount, 2),
            'standard' => round($amount, 2),
            'waived' => false,
        ];
    }

    protected static function extraQty(string $unit, array $ctx): float
    {
        return (float) match ($unit) {
            'per_day' => $ctx['days'],
            'per_hour' => $ctx['hours'],
            'per_guest', 'per_person' => $ctx['guests'],
            default => 1,
        };
    }

    protected static function extraAmount(float $price, string $unit, array $ctx): float
    {
        return $unit === 'percent' ? round($ctx['base'] * $price / 100, 2) : round($price * self::extraQty($unit, $ctx), 2);
    }

    protected static function deposit(Model $item): float
    {
        return ($item instanceof Car || $item instanceof Yacht) && $item->deposit ? (float) $item->deposit : 0.0;
    }

    protected static function whole(float $n): int|float
    {
        return floor($n) == $n ? (int) $n : $n;
    }

    protected static function num($value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    protected static function value($v): ?string
    {
        return $v instanceof \BackedEnum ? (string) $v->value : ($v === null || $v === '' ? null : (string) $v);
    }

    public static function qtyText(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    public static function unitLabel(?string $unit, float $qty = 1): string
    {
        $one = [
            'hour' => 'hour', 'day' => 'day', 'week' => 'week', 'month' => 'month', 'half_day' => 'half day',
            'full_day' => 'full day', 'guest' => 'guest', 'trip' => 'trip', 'package' => 'package',
        ][$unit] ?? (string) $unit;

        return $qty > 1 && ! in_array($unit, ['half_day', 'full_day'], true) ? $one.'s' : $one;
    }
}
