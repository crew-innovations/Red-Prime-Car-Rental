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
                    'AED '.number_format((float) $record->total, 2).' (price list AED '.number_format((float) $record->standard_amount, 2).'). Reason: '.$record->adjustment_reason, $url, null, 'warning', event: 'price_approval',
                    actions: [['approvePrice', 'Approve', 'rp-approve-price', ['type' => $record->getMorphClass(), 'id' => $record->getKey()], 'success']]), report: false);
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
        return ['base_amount', 'extras', 'discount', 'adjustment_reason', 'base_discount_percent', 'discount_mode', 'discount_percent'];
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
            $this->base_discount_percent = null; // no price list to take a % off
            $this->applyOverallDiscount();
            $this->guardAdjustment(null);
            $this->recordListDiscount();

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
        // % off the rental line (4 Nov): follows the price list when the dates or car change.
        $basePercent = $this->base_discount_percent !== null && $this->base_discount_percent !== '' ? (float) $this->base_discount_percent : null;
        $this->base_discount_percent = $basePercent ?: null;
        if ($basePercent) {
            $this->base_amount = \App\Support\Pricing\Discounts::apply((float) $result['base_amount'], $basePercent);
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
            // % off this line (4 Nov): taken off the current price-list amount.
            $percent = ! $waived && isset($was['discount_percent']) && $was['discount_percent'] !== '' && (float) $was['discount_percent'] > 0
                ? min(100.0, (float) $was['discount_percent']) : null;

            return array_merge($line, [
                'waived' => $waived,
                'discount_percent' => $percent,
                'amount' => $waived ? 0.0 : ($percent ? \App\Support\Pricing\Discounts::apply((float) $line['standard'], $percent) : ($changed ? round($typed, 2) : $line['standard'])),
            ]);
        });
        $manual = collect((array) ($this->extras ?? []))->filter(fn ($l) => blank($l['key'] ?? null))
            ->map(fn ($l) => ['label' => (string) ($l['label'] ?? 'Extra'), 'amount' => round((float) ($l['amount'] ?? 0), 2), 'kind' => 'manual']);

        $this->extras = $lines->concat($manual)->values()->all();
        $this->extras_amount = round(collect($this->extras)->sum('amount'), 2);
        $this->standard_amount = round($result['base_amount'] + $lines->sum('standard') + $manual->sum('amount'), 2);
        $this->price_breakdown = $result;

        $this->applyOverallDiscount();
        $this->guardAdjustment($result);
        $this->recordListDiscount();
    }

    /** Overall discount as a % of rental + extras (before VAT), worked out on the server. */
    protected function applyOverallDiscount(): void
    {
        $mode = $this->discount_mode === 'percent' ? 'percent' : ($this->discount_mode ? 'amount' : null);
        $this->discount_mode = $mode;
        if ($mode !== 'percent') {
            $this->discount_percent = null;

            return;
        }
        $p = min(100.0, max(0.0, (float) $this->discount_percent));
        $this->discount_percent = $p ?: null;
        $gross = (float) $this->base_amount + (float) collect((array) ($this->extras ?? []))->sum(fn ($l) => (float) ($l['amount'] ?? 0));
        $this->discount = round($gross * $p / 100, 2);
    }

    /** What was taken off the price list, kept for the "Discounts given" report (4 Nov). */
    protected function recordListDiscount(): void
    {
        if ($this->standard_amount === null || (float) $this->standard_amount <= 0) {
            $off = (float) $this->discount;
            $gross = (float) $this->base_amount + (float) collect((array) ($this->extras ?? []))->sum(fn ($l) => (float) ($l['amount'] ?? 0));
            $this->list_discount_amount = $off > 0 ? round($off, 2) : null;
            $this->list_discount_percent = $off > 0 && $gross > 0 ? round($off / $gross * 100, 2) : null;

            return;
        }
        $off = round((float) $this->standard_amount - $this->netBeforeTax(), 2);
        $this->list_discount_amount = $off > 0.005 ? $off : null;
        $this->list_discount_percent = $off > 0.005 ? \App\Support\Pricing\Discounts::percentOff((float) $this->standard_amount, $this->netBeforeTax()) : null;
    }

    /** Rental + extras − discount, before VAT. */
    public function netBeforeTax(): float
    {
        $extras = collect((array) ($this->extras ?? []))->sum(fn ($l) => (float) ($l['amount'] ?? 0));

        return round((float) $this->base_amount + $extras - (float) $this->discount, 2);
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
                $this->price_rejected_reason = null;
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

        $standard = (float) $this->standard_amount;
        $off = $standard > 0 ? \App\Support\Pricing\Discounts::percentOff($standard, $net) : null;
        $isDiscount = $standard > 0 ? $net < $standard - 0.005 : (float) $this->discount > 0;

        // 4 Nov (10): a special rate and a discount on top of it don't stack unless Business settings allow it.
        if ($isDiscount && ! empty($result['rule']) && ! \App\Support\Pricing\Discounts::stacksWithRules()) {
            throw ValidationException::withMessages(['data.discount' => 'The special rate "'.($result['rule']['name'] ?? '').'" already applies, so no discount can be added on top (Business settings › VAT & quotations).']);
        }

        // A reason for every change, except a small discount (4 Nov: "a reason above a set %").
        $reasonFrom = \App\Support\Pricing\Discounts::reasonFrom();
        $needsReason = ! ($isDiscount && $off !== null && $reasonFrom !== null && $off <= $reasonFrom + 0.001);
        if (blank($this->adjustment_reason)) {
            if (! $user) {
                // Imports, demo data and other saves without a signed-in person.
                $this->adjustment_reason = 'Entered without a signed-in user (import or system).';
            } elseif ($needsReason) {
                throw ValidationException::withMessages(['data.adjustment_reason' => $isDiscount && $reasonFrom !== null
                    ? 'Say why: discounts above '.\App\Support\Pricing\Discounts::percentText($reasonFrom).' need a reason.'
                    : 'Say why the price is different from the price list.']);
            }
        }

        // Limits: per role (4 Nov) or the single limit from before.
        $over = false;
        if ($user && $isDiscount && $off !== null) {
            [$max, $waits] = \App\Support\Pricing\Discounts::limitFor($user);
            if ($max !== null && $off > $max + 0.001) {
                if (! $waits) {
                    throw ValidationException::withMessages(['data.discount' => 'The most you can take off is '.\App\Support\Pricing\Discounts::percentText($max).' of the price list. A manager can give more.']);
                }
                $over = true;
            }
        }
        $this->price_approval = $over ? 'pending' : null;
        $this->price_approved_by = null;
        $this->price_approved_at = null;
        $this->price_rejected_reason = null;
        $this->adjusted_by = $user?->id ?? $this->adjusted_by;
    }

    public function priceWaiting(): bool
    {
        return $this->price_approval === 'pending';
    }

    /** Waiting for a manager, or turned down: it can't be sent or confirmed. */
    public function priceBlocked(): bool
    {
        return in_array($this->price_approval, ['pending', 'rejected'], true);
    }

    /** The person who set the price (or owns the record), told about the manager's answer. */
    public function priceOwner(): ?\App\Models\User
    {
        $id = $this->adjusted_by ?: ($this instanceof \App\Models\Booking ? $this->assigned_to : $this->created_by);

        return $id ? \App\Models\User::find($id) : null;
    }

    protected function priceRecordUrl(): string
    {
        return $this instanceof \App\Models\Booking
            ? \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $this])
            : \App\Filament\Resources\QuotationResource::getUrl('edit', ['record' => $this]);
    }

    /** A manager turns the price down (4 Nov, 10): the agent changes it and it is checked again. */
    public function rejectPrice(string $reason, ?\App\Models\User $by = null): void
    {
        $by ??= auth()->user();
        if (! $this->priceWaiting()) {
            return;
        }
        if (! $by || ! $by->can('approve-prices')) {
            throw ValidationException::withMessages(['price' => 'Only a manager can turn this price down.']);
        }
        $this->forceFill(['price_approval' => 'rejected', 'price_approved_by' => $by->id, 'price_approved_at' => now(), 'price_rejected_reason' => mb_substr($reason, 0, 255)])->saveQuietly();
        \App\Models\AuditLog::note($this, 'override', 'Price turned down by '.$by->name.': '.$reason);
        $owner = $this->priceOwner();
        if ($owner && $owner->id !== $by->id) {
            rescue(fn () => \App\Support\Drivers\Notify::person($owner, 'Price not approved: '.($this->number ?? $this->reference),
                $by->name.': '.$reason.'. Change the price and save it again.', $this->priceRecordUrl(), 'danger', event: 'price_approval'), report: false);
        }
    }

    /** A manager OKs a price below the limit (28 Oct-2, optional). */
    public function approvePrice(?\App\Models\User $by = null): void
    {
        $by ??= auth()->user();
        if (! $this->priceWaiting()) {
            return;
        }
        if (! $by || ! $by->can('approve-prices')) {
            throw ValidationException::withMessages(['price' => 'Only a manager can approve this price.']);
        }
        $this->forceFill(['price_approval' => 'approved', 'price_approved_by' => $by->id, 'price_approved_at' => now()])->saveQuietly();
        \App\Models\AuditLog::note($this, 'override', 'Price approved by '.$by->name.': AED '.number_format((float) $this->total, 2).' (price list AED '.number_format((float) $this->standard_amount, 2).')');
        $owner = $this->priceOwner();
        if ($owner && $owner->id !== $by->id) {
            rescue(fn () => \App\Support\Drivers\Notify::person($owner, 'Price approved: '.($this->number ?? $this->reference),
                'AED '.number_format((float) $this->total, 2).' approved by '.$by->name.'.', $this->priceRecordUrl(), 'success', event: 'price_approval'), report: false);
        }
    }
}
