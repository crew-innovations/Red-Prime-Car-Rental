<?php

namespace App\Support\Reports;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Accounts\MoneyAccounts;
use Illuminate\Support\Carbon;

/**
 * 19 Nov (suggestions 2 and 4): the rental reports AppicFleet has. Reads what is stored; stores nothing.
 *  - out & in: every handover and return in the period (km, fuel, who);
 *  - car use: days each car was rented in the period, and its revenue;
 *  - unpaid: car rentals out or returned with money still owed;
 *  - VAT by emirate: invoices issued in the period, grouped by the emirate of the rental;
 *  - cash & bank: money in and out per account, cheques received.
 */
class RentalReport
{
    public function __construct(public Carbon $from, public Carbon $until) {}

    public function movements(): array
    {
        $fuel = fn ($v) => $v === null || $v === '' ? '—' : \App\Support\Fuel\Fuel::label($v);
        $rows = [];
        $base = fn () => Booking::query()->where('service_type', 'car')->with(['customer', 'bookable', 'deliveredBy', 'receivedBy', 'contract']);
        foreach ($base()->whereBetween('delivered_at', [$this->from, $this->until])->get() as $b) {
            $rows[] = ['at' => $b->delivered_at, 'kind' => 'Out', 'b' => $b, 'km' => $b->delivery_km, 'fuel' => $fuel($b->delivery_fuel_8 ?? $b->delivery_fuel), 'by' => $b->deliveredBy?->name];
        }
        foreach ($base()->whereBetween('returned_at', [$this->from, $this->until])->get() as $b) {
            $rows[] = ['at' => $b->returned_at, 'kind' => 'In', 'b' => $b, 'km' => $b->return_km, 'fuel' => $fuel($b->return_fuel_8 ?? $b->return_fuel), 'by' => $b->receivedBy?->name];
        }

        return collect($rows)->sortBy('at')->values()->all();
    }

    /** Per car: rented days in the period (out with a customer or booked), share of the period, revenue earned. */
    public function carUse(): array
    {
        $days = max(1, $this->from->copy()->startOfDay()->diffInDays($this->until->copy()->startOfDay()) + 1);
        $bookings = Booking::query()->where('bookable_type', 'car')
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed, BookingStatus::PendingPayment])
            ->where('start_at', '<', $this->until)->where('end_at', '>', $this->from)->get()->groupBy('bookable_id');

        return Car::query()->orderBy('name')->get()->map(function (Car $car) use ($bookings, $days) {
            $list = $bookings->get($car->id, collect());
            $hours = $list->sum(function (Booking $b) {
                $s = $b->start_at->max($this->from);
                $e = $b->end_at->min($this->until);

                return max(0, $s->diffInMinutes($e)) / 60;
            });
            $rented = round($hours / 24, 1);

            return [
                'car' => $car, 'bookings' => $list->count(), 'days' => $rented, 'percent' => round(min(100, $rented / $days * 100)),
                'revenue' => round($list->sum(fn (Booking $b) => (float) $b->total - (float) $b->tax_amount), 2),
            ];
        })->sortByDesc('percent')->values()->all();
    }

    public function unpaid(): array
    {
        return Booking::query()->where('service_type', 'car')->where('balance', '>', 0)
            ->whereIn('status', [BookingStatus::InProgress, BookingStatus::Completed])
            ->with(['customer', 'bookable', 'contract'])->orderBy('end_at')->get()->all();
    }

    /** Invoices (minus credit notes) issued in the period, per emirate of the rental. */
    public function vatByEmirate(): array
    {
        $default = (string) Setting::read('tax_emirate_default', 'dubai');
        $out = [];
        Invoice::query()->where('status', 'issued')->whereBetween('issued_on', [$this->from->toDateString(), $this->until->toDateString()])
            ->with('booking')->get()->each(function (Invoice $i) use (&$out, $default) {
                $em = $i->booking?->tax_emirate ?: $default;
                $sign = $i->type === 'credit_note' ? -1 : 1;
                $out[$em] ??= ['net' => 0.0, 'vat' => 0.0, 'count' => 0];
                $out[$em]['net'] += $sign * ((float) $i->total - (float) $i->tax_amount);
                $out[$em]['vat'] += $sign * (float) $i->tax_amount;
                $out[$em]['count']++;
            });

        return collect($out)->map(fn ($r) => ['net' => round($r['net'], 2), 'vat' => round($r['vat'], 2), 'count' => $r['count']])->all();
    }

    public function cash(): array
    {
        return MoneyAccounts::summary($this->from, $this->until);
    }

    public function cheques(): array
    {
        return Transaction::query()->where('method', PaymentMethod::Cheque->value)->where('is_balance', false)->whereNull('reversed_at')
            ->whereBetween('date', [$this->from->toDateString(), $this->until->toDateString()])->with(['booking', 'customer'])->orderBy('cheque_date')->get()->all();
    }
}
