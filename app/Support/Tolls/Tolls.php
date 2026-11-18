<?php

namespace App\Support\Tolls;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Setting;
use App\Models\TollAccount;
use App\Models\TollAccountMovement;
use App\Models\TollTrip;
use App\Models\Transaction;
use App\Support\Charges\ChargeLines;
use App\Support\Fines\VehicleUse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salik, Darb and parking (4 Nov, section 3). Trips are matched to the booking that had the car.
 * Per booking and toll type there is one rolled-up charge line ("Salik: 14 trips, AED 56.00"),
 * updated while it is not invoiced; later trips start a new line. A service fee per trip
 * (setting, empty = none) is a separate revenue line. Trips nobody pays become a company cost.
 */
class Tolls
{
    public static function match(TollTrip $trip): TollTrip
    {
        if (in_array($trip->status, ['charged', 'company'], true)) {
            return $trip;
        }
        $car = $trip->car ?? VehicleUse::car($trip->plate, $trip->tag_no);
        if (! $car) {
            $trip->forceFill(['status' => 'new', 'match_note' => 'No car with tag / plate '.($trip->tag_no ?: $trip->plate ?: '—').'.'])->save();

            return $trip;
        }
        $use = VehicleUse::at($car, $trip->passed_at);
        $trip->forceFill([
            'car_id' => $car->id,
            'booking_id' => $use['booking']?->id,
            'status' => $use['kind'] === 'customer' ? 'matched' : 'new',
            'match_note' => mb_substr($use['note'], 0, 255),
        ])->save();

        return $trip;
    }

    public static function autoCharge(): bool
    {
        return (bool) Setting::read('toll_auto_charge', true);
    }

    /** Service fee per trip for a toll type (empty = none). */
    public static function fee(string $type): float
    {
        $fees = (array) Setting::read('toll_service_fee', []);

        return round(max(0, (float) ($fees[$type] ?? 0)), 2);
    }

    /** Puts every matched trip of a booking on its rolled-up lines. Returns how many trips were added. */
    public static function chargeBooking(Booking $b): int
    {
        if (in_array($b->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            return 0;
        }
        $added = 0;
        DB::transaction(function () use ($b, &$added) {
            $trips = TollTrip::query()->where('booking_id', $b->id)->where('status', 'matched')->lockForUpdate()->orderBy('passed_at')->get();
            foreach ($trips->groupBy('toll_type') as $type => $group) {
                $line = BookingCharge::query()->where('booking_id', $b->id)->where('type', $type)->whereNull('source_type')
                    ->whereNull('voided_at')->whereNull('invoice_id')->latest('id')->first();
                $already = $line ? TollTrip::query()->where('booking_charge_id', $line->id)->get() : collect();
                $all = $already->concat($group);
                $label = TollTrip::TYPES[$type].': '.$all->count().' '.($type === 'parking' ? 'charge(s)' : 'trip(s)').', '.$all->min('passed_at')->format('d M').'–'.$all->max('passed_at')->format('d M');
                $sum = round((float) $all->sum('amount'), 2);
                $line = $line ? ChargeLines::update($line, $sum, $all->count(), $label) : ChargeLines::add($b, $type, $label, $sum, null, $all->count());
                TollTrip::query()->whereIn('id', $group->pluck('id'))->update(['status' => 'charged', 'booking_charge_id' => $line->id, 'updated_at' => now()]);
                $added += $group->count();

                if (($fee = self::fee($type)) > 0) {
                    $feeLine = BookingCharge::query()->where('booking_id', $b->id)->where('type', 'toll_fee')->where('label', 'like', TollTrip::TYPES[$type].'%')
                        ->whereNull('voided_at')->whereNull('invoice_id')->latest('id')->first();
                    $n = (int) ($feeLine ? $feeLine->quantity : 0) + $group->count();
                    $feeLabel = TollTrip::TYPES[$type].' service fee: '.$n.' × AED '.number_format($fee, 2);
                    $feeLine ? ChargeLines::update($feeLine, $n * $fee, $n, $feeLabel) : ChargeLines::add($b, 'toll_fee', $feeLabel, $n * $fee, null, $n, $fee, 'revenue');
                }
            }
        });
        if ($added) {
            AuditLog::note($b, 'tolls', $added.' toll trip(s) added to the booking');
            $b->refresh();
            if ($b->status === BookingStatus::Completed && $b->depositHeld() <= 0) {
                rescue(fn () => \App\Models\Invoice::issueCharges($b), report: false);
            }
            \App\Support\Drivers\Notify::customer($b->customer, 'Tolls added to your rental '.$b->reference,
                $added.' Salik / Darb / parking charge(s) were added. You can see each one in your account.',
                url('/account/bookings/'.$b->id), 'fine_charged', $b, 'tolls:'.$b->id.':'.now()->format('YmdH'), 'info');
        }

        return $added;
    }

    /** Trips nobody pays (our own use, no booking): one company-cost expense for the selection. */
    public static function companyCost(Collection $trips, string $note): ?Transaction
    {
        $trips = $trips->filter(fn (TollTrip $t) => in_array($t->status, ['new', 'matched'], true));
        if ($trips->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($trips, $note) {
            $t = Transaction::create([
                'type' => TransactionType::Expense,
                'category' => 'tolls',
                'date' => today(),
                'amount' => round((float) $trips->sum('amount'), 2),
                'status' => PaymentStatus::Paid,
                'method' => 'other',
                'description' => mb_substr($trips->count().' toll trip(s) as company cost: '.$note, 0, 255),
            ]);
            TollTrip::query()->whereIn('id', $trips->pluck('id'))->update(['status' => 'company', 'transaction_id' => $t->id, 'match_note' => mb_substr($note, 0, 255), 'updated_at' => now()]);

            return $t;
        });
    }

    /** Top up the Salik / Darb account: a transfer (not a cost). The trips are the cost. */
    public static function topUp(TollAccount $a, float $amount, string $method, ?string $note = null, $date = null): TollAccountMovement
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }

        return DB::transaction(function () use ($a, $amount, $method, $note, $date) {
            $a = TollAccount::query()->lockForUpdate()->findOrFail($a->id);
            $t = Transaction::create([
                'type' => TransactionType::Expense, 'category' => 'toll_topup', 'date' => $date ?? today(), 'amount' => round($amount, 2),
                'status' => PaymentStatus::Paid, 'method' => $method, 'description' => 'Top-up '.$a->name.($note ? ' · '.$note : ''),
            ]);
            $a->forceFill(['balance' => round((float) $a->balance + $amount, 2)])->save();

            return $a->movements()->create(['kind' => 'topup', 'amount' => round($amount, 2), 'balance_after' => $a->balance, 'date' => $date ?? today(),
                'note' => $note, 'transaction_id' => $t->id, 'created_by' => auth()->id()]);
        });
    }

    /** Usage from a statement (the imported trips) lowers the balance. */
    public static function usage(TollAccount $a, float $amount, string $note, $date = null): ?TollAccountMovement
    {
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($a, $amount, $note, $date) {
            $a = TollAccount::query()->lockForUpdate()->findOrFail($a->id);
            $a->forceFill(['balance' => round((float) $a->balance - $amount, 2)])->save();
            $m = $a->movements()->create(['kind' => 'statement', 'amount' => -round($amount, 2), 'balance_after' => $a->balance, 'date' => $date ?? today(),
                'note' => mb_substr($note, 0, 255), 'created_by' => auth()->id()]);
            if ($a->isLow()) {
                \App\Support\Drivers\Notify::staff('manage-finance', $a->name.' balance is low', 'AED '.number_format((float) $a->balance, 2).' left (alert below AED '.number_format((float) $a->low_balance, 2).').',
                    null, null, 'warning', event: 'other', dedupe: 'toll_low:'.$a->id.':'.today()->toDateString());
            }

            return $m;
        });
    }
}
