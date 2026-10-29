<?php

namespace App\Support\Suppliers;

use App\Enums\BookingStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Supplier;
use App\Models\SupplierRate;
use App\Models\Transaction;
use App\Models\Yacht;
use App\Support\Accounts\ProfitReport;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Supplier cost of a booking (brief 29 Oct, 11–14).
 *
 * Which rate (first match wins):
 *   1. a booking override (Finance, with a reason)
 *   2. the vehicle's own rate valid on the booking's start date
 *   3. the supplier's default rate valid on that date
 * No rate → "missing": never assumed to be zero, and the booking can't be confirmed.
 *
 * How much:
 *   per booking  1 × amount
 *   per day      the days charged to the customer (every started 24 h)
 *   per week     days ÷ 7 (pro rata) or every started week (full)
 *   per month    days ÷ 30 (pro rata) or every started month (full)
 *   per km       transfers: the route km (both legs on a return); rentals: odometer at return − at handover
 *   percent      of the booking revenue without VAT (price + return charges)
 *
 * The result is kept on the booking (snapshot). Once the supplier bill is posted the
 * snapshot is frozen; later rate changes never touch it.
 */
class SupplierCost
{
    /** Statuses that need a known supplier cost (Bilal, 29 Oct: no confirmation without it). */
    public const NEEDS_COST = [BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed];

    public static function vehicle(Booking $b): Car|Yacht|null
    {
        return match (true) {
            in_array($b->service_type, [ServiceType::Car, ServiceType::Transfer], true) && $b->bookable_id => Car::withTrashed()->find($b->bookable_id),
            $b->service_type === ServiceType::Yacht && $b->bookable_id => Yacht::withTrashed()->find($b->bookable_id),
            default => null,
        };
    }

    /** The supplier this booking owes (null = our own vehicle). */
    public static function supplierFor(Booking $b, Car|Yacht|null $v = null): ?Supplier
    {
        $v ??= self::vehicle($b);
        if (! $v || ! in_array($v->ownership, ['partner', 'cross_hire'], true)) {
            return null;
        }
        $id = $v->ownership === 'cross_hire' ? ($b->supplier_id ?: $v->supplier_id) : $v->supplier_id;

        return $id ? Supplier::withTrashed()->find($id) : null;
    }

    public static function rateFor(Supplier $s, Car|Yacht|null $v, Carbon $day): ?SupplierRate
    {
        $pick = fn ($q) => $q->where('supplier_id', $s->id)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $day->toDateString()))
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $day->toDateString()))
            ->orderByDesc('starts_on')->orderByDesc('id')->first();

        return ($v ? $pick(SupplierRate::query()->where('vehicle_type', $v->getMorphClass())->where('vehicle_id', $v->id)) : null)
            ?? $pick(SupplierRate::query()->whereNull('vehicle_type'));
    }

    /** Days charged to the customer: every started 24 hours (same as the price engine). */
    public static function days(Booking $b): int
    {
        if (! $b->start_at || ! $b->end_at) {
            return 1;
        }
        $hours = max(1, (int) ceil(max(1, $b->start_at->diffInMinutes($b->end_at)) / 60));

        return max(1, (int) ceil($hours / 24));
    }

    /** null when the km are not known yet (no route, odometer not read). */
    public static function km(Booking $b): ?float
    {
        if ($b->service_type === ServiceType::Transfer) {
            if (! (float) $b->route_km) {
                return null;
            }
            $back = $b->journey_type === 'return' || $b->return_at ? ((float) $b->return_route_km ?: (float) $b->route_km) : 0;

            return round((float) $b->route_km + $back, 1);
        }
        if ($b->delivery_km !== null && $b->return_km !== null && (int) $b->return_km >= (int) $b->delivery_km) {
            return (float) ((int) $b->return_km - (int) $b->delivery_km);
        }

        return null;
    }

    /** The snapshot for this booking as it is now (does not save). Null = our own vehicle. */
    public static function calculate(Booking $b): ?array
    {
        $v = self::vehicle($b);
        if (! $v || ! in_array($v->ownership, ['partner', 'cross_hire'], true)) {
            return null;
        }
        $base = ['vehicle_type' => $v->getMorphClass(), 'vehicle_id' => $v->id, 'vehicle' => $v->name, 'ownership' => $v->ownership, 'calculated_at' => now()->toDateTimeString()];
        $s = self::supplierFor($b, $v);
        if (! $s) {
            return $base + ['status' => 'missing', 'reason' => $v->ownership === 'cross_hire' ? 'Choose the supplier this vehicle is hired from.' : $v->name.' has no supplier set (Fleet › '.$v->name.').'];
        }
        $base += ['supplier_id' => $s->id, 'supplier' => $s->name];
        if ($s->status === 'blocked') {
            return $base + ['status' => 'missing', 'reason' => $s->name.' is blocked.'];
        }
        $day = ($b->start_at ?? now())->copy();
        $rate = self::rateFor($s, $v, $day);
        if (! $rate) {
            return $base + ['status' => 'missing', 'reason' => 'No cost rate for '.$v->name.' or '.$s->name.' on '.$day->format('d M Y').' (Suppliers › '.$s->name.' › Cost rates).'];
        }
        $base += ['rate_id' => $rate->id, 'method' => $rate->method, 'rate' => (float) $rate->amount, 'source' => $rate->vehicle_id ? 'vehicle' : 'supplier',
            'rate_starts_on' => $rate->starts_on?->toDateString(), 'partial' => $rate->partial];

        $days = self::days($b);
        [$qty, $label] = match ($rate->method) {
            'per_booking' => [1.0, '1 booking'],
            'per_day' => [(float) $days, $days.' day'.($days > 1 ? 's' : '')],
            'per_week' => $rate->partial === 'full' ? [(float) ceil($days / 7), ceil($days / 7).' week(s), started weeks count in full'] : [round($days / 7, 4), $days.' days ÷ 7'],
            'per_month' => $rate->partial === 'full' ? [(float) ceil($days / 30), ceil($days / 30).' month(s), started months count in full'] : [round($days / 30, 4), $days.' days ÷ 30'],
            'per_km' => [self::km($b), self::km($b) !== null ? self::km($b).' km' : null],
            'percent' => [ProfitReport::earnedFor($b), 'of AED '.number_format(ProfitReport::earnedFor($b), 2).' revenue without VAT'],
        };
        if ($qty === null) {
            return $base + ['status' => 'pending', 'qty' => null, 'reason' => $b->service_type === ServiceType::Transfer ? 'Route km not known yet.' : 'Known after the odometer is read at return.'];
        }
        $cost = match (true) {
            $rate->method === 'percent' => round($qty * (float) $rate->amount / 100, 2),
            // Pro rata from the exact days, not the rounded quantity shown.
            $rate->method === 'per_week' && $rate->partial !== 'full' => round($days * (float) $rate->amount / 7, 2),
            $rate->method === 'per_month' && $rate->partial !== 'full' => round($days * (float) $rate->amount / 30, 2),
            default => round($qty * (float) $rate->amount, 2),
        };

        return $base + ['status' => 'calculated', 'qty' => $qty, 'qty_label' => $label, 'cost' => $cost];
    }

    /** Booking saving hook: keeps the snapshot current until the bill is posted. */
    public static function apply(Booking $b): void
    {
        if (self::billPosted($b)) {
            return; // frozen: the bill is the record now
        }
        $relevant = ['bookable_id', 'bookable_type', 'service_type', 'supplier_id', 'start_at', 'end_at', 'route_km', 'return_route_km', 'journey_type', 'return_at',
            'delivery_km', 'return_km', 'total', 'tax_amount', 'extra_charges', 'status'];
        if ($b->exists && ! $b->isDirty($relevant) && $b->supplier_cost_status !== null) {
            return;
        }

        $snap = self::calculate($b);
        $override = $b->supplier_cost_status === 'override' ? (array) data_get($b->getOriginal('supplier_cost_snapshot') ?? $b->supplier_cost_snapshot, 'override') : null;
        $vehicleChanged = $b->exists && $b->isDirty(['bookable_id', 'bookable_type', 'supplier_id']);

        if (! $snap) {
            $b->forceFill(['supplier_cost_status' => null, 'supplier_cost' => null, 'supplier_cost_snapshot' => null]);
            if (($v = self::vehicle($b)) && $v->ownership === 'own') {
                $b->supplier_id = null;
            }
        } elseif ($override && ! $vehicleChanged) {
            // An approved override stays; the calculated figure is kept next to it for the record.
            $b->forceFill(['supplier_id' => $snap['supplier_id'] ?? $b->supplier_id, 'supplier_cost' => $override['amount'], 'supplier_cost_snapshot' => $snap + ['override' => $override]]);
        } else {
            $b->forceFill([
                'supplier_id' => $snap['supplier_id'] ?? ($b->supplier_id ?: null),
                'supplier_cost_status' => $snap['status'],
                'supplier_cost' => $snap['cost'] ?? null,
                'supplier_cost_snapshot' => $snap,
            ]);
        }

        // Bilal, 29 Oct: no confirmation while a supplier vehicle has no cost. Only the step into
        // "confirmed" (or a new vehicle on a confirmed booking) is stopped: a booking confirmed
        // before can still be handed over and returned, and Finance is told at completion.
        $was = $b->exists ? $b->getOriginal('status') : null;
        $was = $was instanceof BookingStatus ? $was : ($was ? BookingStatus::tryFrom((string) $was) : null);
        $entering = ! $b->exists || $b->isDirty(['bookable_id', 'bookable_type', 'supplier_id'])
            || ($b->isDirty('status') && ! in_array($was, self::NEEDS_COST, true));
        if ($entering && $b->supplier_cost_status === 'missing' && in_array($b->status, [BookingStatus::Confirmed, BookingStatus::InProgress], true)) {
            throw ValidationException::withMessages(['data.status' => 'Supplier cost not set: '.data_get($b->supplier_cost_snapshot, 'reason').' A manager can also set the cost for this booking (Money › Supplier cost).']);
        }
    }

    public static function billPosted(Booking $b): bool
    {
        if (! $b->supplier_bill_id) {
            return false;
        }
        $bill = Transaction::withTrashed()->find($b->supplier_bill_id);

        return $bill && ! $bill->reversed_at && ! $bill->trashed();
    }

    /** Finance sets the cost for one booking (exceptional cost or no rate). */
    public static function override(Booking $b, float $amount, string $reason, ?\App\Models\User $by = null): void
    {
        $by ??= auth()->user();
        abort_unless($by && \App\Support\Access::allows('manage-finance'), 403);
        if (self::billPosted($b)) {
            throw ValidationException::withMessages(['data.amount' => 'The supplier bill is already posted. Reverse it in Accounts first.']);
        }
        if ($amount < 0) {
            throw ValidationException::withMessages(['data.amount' => 'The cost can\'t be negative.']);
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['data.reason' => 'Say why.']);
        }
        $snap = self::calculate($b);
        if (! $snap) {
            throw ValidationException::withMessages(['data.amount' => 'This booking uses our own vehicle: there is no supplier cost.']);
        }
        if (empty($snap['supplier_id'])) {
            throw ValidationException::withMessages(['data.amount' => $snap['reason'] ?? 'Set the supplier first.']);
        }
        $override = ['amount' => round($amount, 2), 'reason' => mb_substr(trim($reason), 0, 255), 'by' => $by->id, 'by_name' => $by->name, 'at' => now()->toDateTimeString(),
            'calculated' => $snap['cost'] ?? null, 'calculated_status' => $snap['status']];
        $b->forceFill(['supplier_id' => $snap['supplier_id'], 'supplier_cost_status' => 'override', 'supplier_cost' => $override['amount'], 'supplier_cost_snapshot' => $snap + ['override' => $override]])->saveQuietly();
        \App\Models\AuditLog::note($b, 'override', 'Supplier cost set to AED '.number_format($amount, 2).' (calculated: '.(isset($snap['cost']) ? 'AED '.number_format($snap['cost'], 2) : $snap['status']).'): '.$override['reason'], ['supplier_cost' => $override]);
    }

    /** Back to the calculated cost. */
    public static function clearOverride(Booking $b): void
    {
        abort_unless(\App\Support\Access::allows('manage-finance'), 403);
        if (self::billPosted($b) || $b->supplier_cost_status !== 'override') {
            return;
        }
        $b->forceFill(['supplier_cost_status' => null, 'supplier_cost' => null, 'supplier_cost_snapshot' => null]);
        self::apply($b);
        $b->saveQuietly();
        \App\Models\AuditLog::note($b, 'override', 'Supplier cost override removed; back to the calculated cost.');
    }
}
