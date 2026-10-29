<?php

namespace App\Models\Concerns;

use App\Models\PackageDeparture;
use App\Models\Setting;
use App\Support\Access;
use App\Support\Pricing\PriceEngine;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * Server-side pricing for quotations and bookings (28 Oct brief, 2.2–2.3).
 *
 * On save the price engine runs again from the saved fields, so the browser is
 * never the source of truth:
 *  - lines from the price list (delivery, fees, chosen extras) are rebuilt;
 *    manual lines without a key are kept as typed;
 *  - an amount still equal to the previous standard follows the new standard;
 *    a different amount counts as an agent change;
 *  - standard_amount keeps the calculated price, so the original and the
 *    agreed price can both be shown;
 *  - any change (other price, discount, waived fee) needs a reason, and the
 *    "Change prices" permission when a person is making it.
 */
trait HasPricing
{
    /** Tell managers when a price starts waiting for their OK (optional approval, 28 Oct-2). */
    public static function bootHasPricing(): void
    {
        static::saved(function ($record) {
            if ($record->wasChanged('price_approval') && $record->price_approval === 'pending') {
                $url = $record instanceof \App\Models\Booking
                    ? \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $record])
                    : \App\Filament\Resources\QuotationResource::getUrl('edit', ['record' => $record]);
                rescue(fn () => \App\Support\Drivers\Notify::staff('approve-prices', 'Price to approve: '.($record->number ?? $record->reference),
                    'AED '.number_format((float) $record->total, 2).' (price list AED '.number_format((float) $record->standard_amount, 2).'). Reason: '.$record->adjustment_reason, $url, null, 'warning'), report: false);
            }
        });
    }

    /** Fields that change what the price list gives. */
    public static function pricingInputs(): array
    {
        return ['service_type', 'bookable_id', 'start_at', 'end_at', 'drive_mode', 'guests', 'package_departure_id', 'delivery_method', 'return_method', 'extras',
            // limousine & transfers (28 Oct-2)
            'transfer_service_id', 'vehicle_category_id', 'journey_type', 'route_km', 'return_route_km', 'route_minutes', 'stops', 'waiting_minutes', 'return_at', 'pickup_location', 'dropoff_location'];
    }

    public static function pricingFields(): array
    {
        return ['base_amount', 'extras', 'discount', 'adjustment_reason'];
    }

    /** Runs the engine for the saved fields. */
    public function priceResult(?array $extraKeys = null): ?array
    {
        $class = $this->service_type ? Relation::getMorphedModel($this->service_type->value === 'transfer' ? 'car' : $this->service_type->value) : null;
        $item = $class && $this->bookable_id ? $class::withTrashed()->find($this->bookable_id) : null;

        return PriceEngine::calculate(\App\Support\Transfers\TransferJourney::priceInput($this) + [
            'service_type' => $this->service_type,
            'item' => $item,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'drive_mode' => $this->drive_mode,
            'guests' => $this->guests,
            'departure' => $this->package_departure_id ? PackageDeparture::find($this->package_departure_id) : null,
            'delivery_method' => $this->delivery_method,
            'return_method' => $this->return_method,
            'extra_keys' => $extraKeys ?? $this->chosenExtraKeys(),
        ]);
    }

    /** Optional extras picked on this record (lines from the price list of kind "extra"). */
    public function chosenExtraKeys(): array
    {
        return collect((array) ($this->extras ?? []))
            ->filter(fn ($l) => filled($l['key'] ?? null) && ($l['kind'] ?? 'extra') === 'extra')
            ->pluck('key')->values()->all();
    }

    /** Called from the model's saving hook. */
    public function applyPricing(): void
    {
        if ($this->exists && ! $this->isDirty(array_merge(static::pricingInputs(), static::pricingFields()))) {
            return;
        }

        // Older bookings kept add-ons as one typed amount: keep it as a manual line.
        if ($this->extras === null && (float) $this->extras_amount > 0) {
            $this->extras = [['label' => 'Add-ons & delivery', 'amount' => round((float) $this->extras_amount, 2)]];
        }

        $result = $this->priceResult();
        $previous = $this->exists ? (array) ($this->getOriginal('price_breakdown') ?? []) : [];

        if (! $result) {
            // Nothing on the price list for this: the price is typed by hand.
            $this->extras_amount = $this->extras !== null ? round(collect((array) $this->extras)->sum(fn ($l) => (float) ($l['amount'] ?? 0)), 2) : ($this->extras_amount ?? 0);
            $this->standard_amount = null;
            $this->price_breakdown = null;
            $this->guardAdjustment(null);

            return;
        }

        // Base price: follows the list unless it was deliberately changed.
        $prevBase = isset($previous['base_amount']) ? (float) $previous['base_amount'] : null;
        if ($prevBase === null && $this->exists) {
            // Saved before the price engine: what did the list give for the old dates and car?
            $before = (clone $this)->setRawAttributes($this->getRawOriginal(), true);
            $prevBase = $before->priceResult()['base_amount'] ?? null;
        }
        $current = $this->base_amount !== null ? (float) $this->base_amount : null;
        $differs = fn (?float $a) => $a === null || abs((float) $current - $a) > 0.005;
        // Still the old list price (dates or car changed): follow the new one. Old records
        // without a saved calculation keep what was typed.
        $baseChanged = $current !== null && $differs($result['base_amount']) && ($prevBase === null || $differs($prevBase));
        if (! $baseChanged) {
            $this->base_amount = $result['base_amount'];
        }
        $this->rate_type = $result['rate_type'];
        $this->quantity = $result['quantity'];
        $this->rate = $result['rate'];

        // Lines: price-list lines rebuilt, agent amounts and waivers kept, manual lines kept.
        $old = collect((array) ($this->extras ?? []))->filter(fn ($l) => filled($l['key'] ?? null))->keyBy('key');
        $lines = collect($result['lines'])->map(function (array $line) use ($old) {
            $was = $old->get($line['key']);
            if (! $was) {
                return $line;
            }
            $waived = (bool) ($was['waived'] ?? false);
            $prevStandard = isset($was['standard']) ? (float) $was['standard'] : null;
            $typed = isset($was['amount']) ? (float) $was['amount'] : $line['standard'];
            $changed = ! $waived && $prevStandard !== null && abs($typed - $prevStandard) > 0.005;

            return array_merge($line, [
                'waived' => $waived,
                'amount' => $waived ? 0.0 : ($changed ? round($typed, 2) : $line['standard']),
            ]);
        });
        $manual = collect((array) ($this->extras ?? []))->filter(fn ($l) => blank($l['key'] ?? null))
            ->map(fn ($l) => ['label' => (string) ($l['label'] ?? 'Extra'), 'amount' => round((float) ($l['amount'] ?? 0), 2), 'kind' => 'manual']);

        $this->extras = $lines->concat($manual)->values()->all();
        $this->extras_amount = round(collect($this->extras)->sum('amount'), 2);
        $this->standard_amount = round($result['base_amount'] + $lines->sum('standard') + $manual->sum('amount'), 2);
        $this->price_breakdown = $result;

        $this->guardAdjustment($result);
    }

    /** Set to price a confirmed transfer with today's rate (explicit, "Change prices" permission). */
    public bool $repriceWithCurrentRate = false;

    public function repriceWithCurrentRate(string $reason): void
    {
        abort_unless(\App\Support\Access::allows('adjust-prices'), 403);
        $before = (float) $this->total;
        $this->repriceWithCurrentRate = true;
        $this->base_amount = null; // follow the current price list
        $this->adjustment_reason = $this->adjustment_reason ?: $reason;
        $this->save();
        $this->repriceWithCurrentRate = false;
        \App\Models\AuditLog::note($this, 'override', 'Priced again with the current transfer rate: AED '.number_format($before, 2).' → AED '.number_format((float) $this->total, 2).'. '.$reason);
    }

    /** Is the agreed price different from the price list (other price, discount or waived fee)? */
    public function isAdjusted(): bool
    {
        if ($this->standard_amount === null) {
            return false;
        }
        $net = (float) $this->base_amount + (float) $this->extras_amount - (float) $this->discount;

        return abs($net - (float) $this->standard_amount) > 0.005;
    }

    public function adjustmentAmount(): float
    {
        if ($this->standard_amount === null) {
            return 0.0;
        }

        return round((float) $this->base_amount + (float) $this->extras_amount - (float) $this->discount - (float) $this->standard_amount, 2);
    }

    protected function guardAdjustment(?array $result): void
    {
        $adjusted = $this->isAdjusted() || ($result === null && (float) $this->discount > 0);
        if (! $adjusted) {
            if ($this->isDirty(['base_amount', 'extras', 'discount', 'standard_amount'])) {
                $this->adjusted_by = null;
                $this->price_approval = null;
            }

            return;
        }

        $net = round((float) $this->base_amount + (float) $this->extras_amount - (float) $this->discount, 2);
        // Only a new or different change is checked: moving the dates of a discounted
        // booking keeps the same discount and needs no new permission.
        $wasStandard = $this->exists ? $this->getOriginal('standard_amount') : null;
        if ($this->exists && $wasStandard !== null) {
            $wasNet = round((float) $this->getOriginal('base_amount') + (float) $this->getOriginal('extras_amount') - (float) $this->getOriginal('discount'), 2);
            $changedNow = abs(($net - (float) $this->standard_amount) - ($wasNet - (float) $wasStandard)) > 0.005;
        } else {
            $changedNow = ! $this->exists || $this->isDirty(['base_amount', 'discount', 'extras']);
        }
        if (! $changedNow) {
            return;
        }

        $user = auth()->user();
        if ($user && ! Access::allows('adjust-prices')) {
            throw ValidationException::withMessages(['data.base_amount' => 'You can\'t change prices, give a discount or waive a fee. The price list gives AED '.number_format((float) $this->standard_amount, 2).'.']);
        }
        if (blank($this->adjustment_reason)) {
            if (! $user) {
                // Imports, demo data and other saves without a signed-in person.
                $this->adjustment_reason = 'Entered without a signed-in user (import or system).';
            } else {
                throw ValidationException::withMessages(['data.adjustment_reason' => 'Say why the price is different from the price list.']);
            }
        }
        $max = Setting::read('price_max_discount_percent');
        $over = false;
        if ($user && $max !== null && $max !== '' && (float) $this->standard_amount > 0 && ! Access::allows('manage-pricing')) {
            $off = ((float) $this->standard_amount - $net) / (float) $this->standard_amount * 100;
            if ($off > (float) $max + 0.001) {
                // Optional (28 Oct-2): above the limit it waits for a manager instead of being refused.
                if (! Setting::read('price_approval_required')) {
                    throw ValidationException::withMessages(['data.discount' => 'The most you can take off is '.rtrim(rtrim(number_format((float) $max, 2), '0'), '.').'% of the price list. A manager can give more.']);
                }
                $over = true;
            }
        }
        $this->price_approval = $over ? 'pending' : null;
        $this->price_approved_by = null;
        $this->price_approved_at = null;
        $this->adjusted_by = $user?->id ?? $this->adjusted_by;
    }

    public function priceWaiting(): bool
    {
        return $this->price_approval === 'pending';
    }

    /** A manager OKs a price below the limit (28 Oct-2, optional). */
    public function approvePrice(?\App\Models\User $by = null): void
    {
        $by ??= auth()->user();
        if (! $this->priceWaiting()) {
            return;
        }
        if (! $by || ! Access::allows('approve-prices')) {
            throw ValidationException::withMessages(['price' => 'Only a manager can approve this price.']);
        }
        $this->forceFill(['price_approval' => 'approved', 'price_approved_by' => $by->id, 'price_approved_at' => now()])->saveQuietly();
        \App\Models\AuditLog::note($this, 'override', 'Price approved by '.$by->name.': AED '.number_format((float) $this->total, 2).' (price list AED '.number_format((float) $this->standard_amount, 2).')');
    }
}
