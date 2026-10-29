<?php

namespace App\Support\Pricing;

use App\Models\PriceExtra;
use App\Models\TransferFixedRoute;
use App\Models\TransferRate;
use App\Models\TransferService;
use App\Models\VehicleCategory;
use Illuminate\Support\Carbon;

/**
 * Limousine and transfer prices (brief 28 Oct-2, 4.4), called by PriceEngine so
 * quotations, bookings, Price check and the website all use one calculation.
 *
 * From Pricing › Transfer rates (every amount set by the admin, empty = not charged):
 *  - distance:  base fee + km × per-km rate, raised to the minimum fare;
 *               or the fixed price of a matching route
 *  - by the hour: hours (at least the minimum) × hourly rate, or the half-day /
 *               full-day price when that is cheaper and the hours fit
 *  - return leg: priced like the way out, less the return discount
 *  - lines: airport fee, extra stops, waiting beyond the free minutes,
 *           night surcharge (pickup time), tolls, catalogue fees and chosen extras
 * The distance is the road distance from Google Maps, or typed by staff
 * ("manual"); never a straight line.
 */
class TransferPricing
{
    public static function calculate(array $in): ?array
    {
        $service = ($in['transfer_service'] ?? null) ?: (filled($in['transfer_service_id'] ?? null) ? TransferService::withTrashed()->find($in['transfer_service_id']) : null);
        $category = ($in['vehicle_category'] ?? null) ?: (filled($in['vehicle_category_id'] ?? null) ? VehicleCategory::withTrashed()->find($in['vehicle_category_id']) : null);
        $start = filled($in['start_at'] ?? null) ? Carbon::parse($in['start_at']) : null;
        if (! $service || ! $category || ! $start) {
            return null;
        }
        // A confirmed booking / sent quotation keeps the rate it was priced with (29 Oct, 7).
        $pinned = filled($in['rate_id'] ?? null) ? TransferRate::withTrashed()->find($in['rate_id']) : null;
        $rate = $pinned && (int) $pinned->vehicle_category_id === (int) $category->id && (! $pinned->transfer_service_id || (int) $pinned->transfer_service_id === (int) $service->id)
            ? $pinned
            : TransferRate::for($category->id, $service->id, $start);
        if (! $rate) {
            return null; // see problem() for why
        }

        $journey = $in['journey_type'] ?? $service->journey_type ?? 'one_way';
        $hourly = $journey === 'hourly';
        $km = self::num($in['route_km'] ?? null);
        $returnKm = self::num($in['return_km'] ?? null);
        $stops = count(array_filter((array) ($in['stops'] ?? []), fn ($s) => filled(is_array($s) ? ($s['address'] ?? null) : $s)));
        $waiting = max(0, (int) ($in['waiting_minutes'] ?? 0));
        $guests = max(0, (int) ($in['guests'] ?? 0));
        $notes = [];
        $warnings = [];

        if ($category->passengers && $guests > $category->passengers) {
            $warnings[] = "{$category->name} takes up to {$category->passengers} passengers.";
        }

        // ---- main fare ----
        if ($hourly) {
            $end = filled($in['end_at'] ?? null) ? Carbon::parse($in['end_at']) : null;
            $hours = $end && $end->gt($start) ? round(ceil($start->diffInMinutes($end) / 15) * 15 / 60, 2) : 0;
            if (! $hours) {
                return null; // see problem() for why
            }
            [$base, $label, $unit, $qty, $unitPrice] = self::hourlyFare($rate, $hours, $notes);
            if ($base === null) {
                return null; // see problem() for why
            }
        } else {
            $fixed = TransferFixedRoute::forRoute($category->id, $in['pickup_location'] ?? null, $in['dropoff_location'] ?? null);
            if ($fixed) {
                [$base, $label, $unit, $qty, $unitPrice] = [(float) $fixed->price, 'Fixed route: '.$fixed->name, 'trip', 1, (float) $fixed->price];
                $notes[] = 'Fixed route price ('.$fixed->name.').';
            } else {
                if ($km === null || $km <= 0) {
                    return null; // see problem() for why
                }
                [$base, $label] = self::distanceFare($rate, $km, $notes);
                [$unit, $qty, $unitPrice] = ['km', $km, (float) ($rate->per_km ?? 0)];
            }
        }

        $ctx = ['base' => $base, 'hours' => $hourly ? ($hours ?? 1) : max(1, (int) ceil(((int) ($in['route_minutes'] ?? 60)) / 60)), 'days' => 1, 'guests' => max(1, $guests)];
        $lines = collect();

        // Return journey priced like the way out (fixed route or distance), less any return discount.
        if (! $hourly && ($journey === 'return' || filled($in['return_at'] ?? null))) {
            $back = isset($fixed) && $fixed ? (float) $fixed->price : self::distanceFare($rate, $returnKm ?: $km, $notes, quiet: true)[0];
            if ($rate->return_discount_percent) {
                $back = round($back * (1 - (float) $rate->return_discount_percent / 100), 2);
            }
            $lines->push(self::line('transfer:return', 'Return journey'.($rate->return_discount_percent ? ' (−'.PriceEngine::qtyText((float) $rate->return_discount_percent).'%)' : ''), 'fee', 'per_booking', $back, $back));
        }
        if ($service->is_airport && (float) $rate->airport_fee > 0) {
            $lines->push(self::line('transfer:airport', 'Airport fee', 'fee', 'per_booking', (float) $rate->airport_fee, (float) $rate->airport_fee));
        }
        if ($stops && (float) $rate->per_stop > 0) {
            $lines->push(self::line('transfer:stops', $stops.' extra stop'.($stops > 1 ? 's' : ''), 'fee', 'per_stop', $stops * (float) $rate->per_stop, (float) $rate->per_stop, $stops));
        }
        $free = (int) ($rate->free_waiting_minutes ?? 0);
        if (! $hourly && $waiting > $free && (float) $rate->waiting_per_hour > 0) {
            $billed = (int) (ceil(($waiting - $free) / 15) * 15); // in quarter hours
            $amount = round($billed / 60 * (float) $rate->waiting_per_hour, 2);
            $lines->push(self::line('transfer:waiting', 'Waiting '.$billed.' min'.($free ? ' (after '.$free.' free)' : ''), 'fee', 'per_hour', $amount, (float) $rate->waiting_per_hour, round($billed / 60, 2)));
        }
        if ((float) $rate->night_percent > 0 && $rate->night_from && $rate->night_to && self::isNight($start, $rate->night_from, $rate->night_to)) {
            $amount = round($base * (float) $rate->night_percent / 100, 2);
            $lines->push(self::line('transfer:night', 'Night surcharge '.PriceEngine::qtyText((float) $rate->night_percent).'%', 'fee', 'percent', $amount, (float) $rate->night_percent));
        }
        if ((float) $rate->toll_fee > 0) {
            $legs = (! $hourly && ($journey === 'return' || filled($in['return_at'] ?? null))) ? 2 : 1;
            $lines->push(self::line('transfer:tolls', 'Tolls (Salik)', 'fee', 'per_booking', $legs * (float) $rate->toll_fee, (float) $rate->toll_fee, $legs));
        }

        // Catalogue fees for transfers and the extras the agent ticked (child seat, flowers…).
        $catalogue = PriceExtra::query()->active()->forService('transfer')->orderBy('sort_order')->get();
        foreach ($catalogue->where('kind', 'fee') as $fee) {
            $amount = self::extraAmount((float) $fee->amount, $fee->unit, $ctx);
            $lines->push(self::line('extra:'.$fee->id, $fee->name, 'fee', $fee->unit, $amount, (float) $fee->amount));
        }
        $options = PriceEngine::optionalExtras('transfer', null);
        foreach (array_unique((array) ($in['extra_keys'] ?? [])) as $key) {
            if (! isset($options[$key]) || $lines->contains('key', $key)) {
                continue;
            }
            $o = $options[$key];
            $lines->push(self::line($key, $o['label'], 'extra', $o['unit'], self::extraAmount($o['price'], $o['unit'], $ctx), $o['price']));
        }

        $extras = round($lines->sum('amount'), 2);

        return [
            'service_type' => 'transfer',
            'item' => ['type' => 'vehicle_category', 'id' => $category->id, 'name' => $category->name],
            'transfer' => [
                'service' => $service->name, 'service_id' => $service->id, 'category' => $category->name, 'category_id' => $category->id,
                'journey' => $journey, 'km' => $km, 'return_km' => $returnKm, 'minutes' => $in['route_minutes'] ?? null,
                'stops' => $stops, 'waiting_minutes' => $waiting, 'route_source' => $in['route_source'] ?? null,
                'rate_id' => $rate->id, 'fixed_route' => isset($fixed) && $fixed ? $fixed->name : null,
            ],
            'start_at' => $start->toDateTimeString(),
            'end_at' => filled($in['end_at'] ?? null) ? Carbon::parse($in['end_at'])->toDateTimeString() : null,
            'drive_mode' => null,
            'guests' => $guests ?: null,
            'hours' => $hourly ? ($hours ?? null) : null,
            'days' => 1,
            'rate_type' => $unit,
            'quantity' => $qty,
            'rate' => round($unitPrice, 2),
            'standard_rate' => round($unitPrice, 2),
            'base_amount' => round($base, 2),
            'base_label' => $label,
            'rule' => null,
            'lines' => $lines->values()->all(),
            'extras_amount' => $extras,
            'standard_amount' => round($base + $extras, 2),
            'deposit' => 0.0,
            'notes' => $notes,
            'warnings' => $warnings,
            'calculated_at' => now()->toDateTimeString(),
        ];
    }

    /** @return array{0: float, 1: string} */
    public static function distanceFare(TransferRate $rate, float $km, array &$notes, bool $quiet = false): array
    {
        $fare = round((float) $rate->base_fee + $km * (float) $rate->per_km, 2);
        $label = ($rate->base_fee ? 'AED '.number_format((float) $rate->base_fee, 2).' + ' : '').PriceEngine::qtyText($km).' km × AED '.number_format((float) $rate->per_km, 2);
        if ($rate->min_fare && $fare < (float) $rate->min_fare) {
            if (! $quiet) {
                $notes[] = 'Minimum fare AED '.number_format((float) $rate->min_fare, 2).' applied.';
            }
            $fare = (float) $rate->min_fare;
            $label = 'Minimum fare';
        }

        return [$fare, $label];
    }

    /** @return array{0: ?float, 1: string, 2: string, 3: float, 4: float} */
    public static function hourlyFare(TransferRate $rate, float $hours, array &$notes): array
    {
        $options = [];
        if ((float) $rate->per_hour > 0) {
            $billed = max($hours, (float) ($rate->min_hours ?? 0));
            if ($billed > $hours) {
                $notes[] = 'Minimum '.PriceEngine::qtyText($billed).' hours.';
            }
            $options[] = [round($billed * (float) $rate->per_hour, 2), PriceEngine::qtyText($billed).' h × AED '.number_format((float) $rate->per_hour, 2), 'hour', $billed, (float) $rate->per_hour];
        }
        if ((float) $rate->half_day_price > 0 && $rate->half_day_hours && $hours <= (float) $rate->half_day_hours) {
            $options[] = [(float) $rate->half_day_price, 'Half day (up to '.PriceEngine::qtyText((float) $rate->half_day_hours).' h)', 'half_day', 1, (float) $rate->half_day_price];
        }
        if ((float) $rate->full_day_price > 0 && $rate->full_day_hours && $hours <= (float) $rate->full_day_hours) {
            $options[] = [(float) $rate->full_day_price, 'Full day (up to '.PriceEngine::qtyText((float) $rate->full_day_hours).' h)', 'full_day', 1, (float) $rate->full_day_price];
        }
        if (! $options) {
            return [null, '', 'hour', 0, 0];
        }
        usort($options, fn ($a, $b) => $a[0] <=> $b[0]); // the cheapest that fits

        return $options[0];
    }

    public static function isNight(Carbon $at, string $from, string $to): bool
    {
        $t = $at->format('H:i:s');
        $from = strlen($from) === 5 ? $from.':00' : $from;
        $to = strlen($to) === 5 ? $to.':00' : $to;

        return $from <= $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to); // e.g. 22:00 → 06:00
    }

    /** Why a transfer can't be priced yet (for forms and the API). */
    public static function problem(array $in): ?string
    {
        if (blank($in['transfer_service_id'] ?? null) && empty($in['transfer_service'])) {
            return 'Pick the service.';
        }
        if (blank($in['vehicle_category_id'] ?? null) && empty($in['vehicle_category'])) {
            return 'Pick the vehicle category.';
        }
        if (blank($in['start_at'] ?? null)) {
            return 'Set the pickup date and time.';
        }
        $service = $in['transfer_service'] ?? TransferService::withTrashed()->find($in['transfer_service_id']);
        $categoryId = ($in['vehicle_category'] ?? null)?->id ?? (int) $in['vehicle_category_id'];
        $rate = TransferRate::for($categoryId, $service?->id, Carbon::parse($in['start_at']));
        if (! $rate) {
            return 'No transfer rate for this vehicle category yet (Pricing › Transfer rates).';
        }
        $journey = $in['journey_type'] ?? $service?->journey_type;
        if ($journey === 'hourly') {
            return blank($in['end_at'] ?? null) ? 'Set the end time to price chauffeur hire by the hour.' : (! ((float) $rate->per_hour || (float) $rate->half_day_price || (float) $rate->full_day_price) ? 'No hourly price for this category.' : null);
        }
        if (! TransferFixedRoute::forRoute($categoryId, $in['pickup_location'] ?? null, $in['dropoff_location'] ?? null) && ! ((float) ($in['route_km'] ?? 0) > 0)) {
            return 'Calculate the route (or type the distance) to price this transfer.';
        }

        return null;
    }

    protected static function line(string $key, string $label, string $kind, string $unit, float $amount, float $price, float $qty = 1): array
    {
        return ['key' => $key, 'label' => $label, 'kind' => $kind, 'unit' => $unit, 'unit_price' => round($price, 2), 'qty' => $qty,
            'amount' => round($amount, 2), 'standard' => round($amount, 2), 'waived' => false];
    }

    protected static function extraAmount(float $price, string $unit, array $ctx): float
    {
        return match ($unit) {
            'percent' => round($ctx['base'] * $price / 100, 2),
            'per_hour' => round($price * $ctx['hours'], 2),
            'per_guest', 'per_person' => round($price * $ctx['guests'], 2),
            default => round($price, 2),
        };
    }

    protected static function num($v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }
}
