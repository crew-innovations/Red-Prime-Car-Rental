<?php

namespace App\Support\Fuel;

use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\FuelEntry;
use App\Models\FuelPrice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Access;
use App\Support\Drivers\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 5 Nov ("Make it simple", Part D, S5): fuel, connected to the rest of the CRM.
 *
 *  - The car knows its tank size, grade and current level (eighths). Every handover, return and
 *    full fill-up updates the level.
 *  - At return, missing eighths × tank × this month's price (+ the refuel fee Admin sets) is
 *    proposed as the "Fuel refill" return charge: it lands on the booking's balance, the invoice
 *    and the deposit settlement like every return charge. Lowering it needs a reason.
 *  - A fill-up from a driver's phone is only a claim: it waits for Accounts. Confirming creates the
 *    fuel expense on the car (and on the booking when the car was out), so car and booking profit
 *    include it. Accounts / Admin entries are confirmed at once. Rejected ones keep their reason.
 *  - Car page and monthly report: litres, spend, km per litre, cost per km, charged to customers,
 *    and a warning when a car suddenly uses much more fuel than usual.
 */
class Fuel
{
    public const GRADES = ['special95' => 'Special 95', 'super98' => 'Super 98', 'diesel' => 'Diesel', 'electric' => 'Electric'];

    public const EIGHTHS = [0 => 'Empty', 1 => '1/8', 2 => '1/4', 3 => '3/8', 4 => '1/2', 5 => '5/8', 6 => '3/4', 7 => '7/8', 8 => 'Full'];

    /** The old quarter values (before 5 Nov) as eighths. */
    public const QUARTERS = ['empty' => 0, 'quarter' => 2, 'half' => 4, 'three_quarter' => 6, 'full' => 8];

    /* ---------- levels ---------- */

    /** An eighth from 0–8, "4", or an old quarter value ("half"); null when unknown. */
    public static function eighth(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && isset(self::QUARTERS[$value])) {
            return self::QUARTERS[$value];
        }

        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 8 ? (int) $value : null;
    }

    /** The nearest old quarter value, still written for older screens and reports. */
    public static function toQuarter(?int $eighth): ?string
    {
        return match (true) {
            $eighth === null => null,
            $eighth === 0 => 'empty',
            $eighth <= 2 => 'quarter',
            $eighth <= 4 => 'half',
            $eighth <= 6 => 'three_quarter',
            default => 'full',
        };
    }

    /** Gauge buttons: E, 1/8 … 7/8, F (keys are the eighths, as strings so 0 is a real choice). */
    public static function gaugeOptions(string $empty = 'E', string $full = 'F'): array
    {
        $o = [];
        foreach (self::EIGHTHS as $k => $v) {
            $o[(string) $k] = $k === 0 ? $empty : ($k === 8 ? $full : $v);
        }

        return $o;
    }

    public static function label(mixed $value): string
    {
        $e = self::eighth($value);

        return $e === null ? '–' : self::EIGHTHS[$e];
    }

    /** Percent for rental-contract PDFs (e.g. 7/8 → "88%"). */
    public static function percentLabel(mixed $value): string
    {
        $e = self::eighth($value);

        return $e === null ? '—' : ((int) round($e / 8 * 100)).'%';
    }

    /** Level at handover or return, reading the old quarter column when the eighths one is empty. */
    public static function bookingLevel(Booking $b, string $stage): ?int
    {
        return $stage === 'return'
            ? ($b->return_fuel_8 ?? self::eighth($b->return_fuel))
            : ($b->delivery_fuel_8 ?? self::eighth($b->delivery_fuel));
    }

    public static function setCarLevel(?Car $car, ?int $eighth, $at = null): void
    {
        if ($car && $eighth !== null) {
            $car->forceFill(['fuel_level_8' => $eighth, 'fuel_level_at' => $at ? Carbon::parse($at) : now()])->saveQuietly();
        }
    }

    /* ---------- prices ---------- */

    /** @return array{0: ?float, 1: ?Carbon} price per litre for the grade, and the month it is for (latest up to that month) */
    public static function price(?string $grade, $date = null): array
    {
        if (! $grade || $grade === 'electric') {
            return [null, null];
        }
        $month = Carbon::parse($date ?? now())->startOfMonth()->toDateString();
        $row = FuelPrice::query()->where('fuel_grade', $grade)->where('month', '<=', $month)->orderByDesc('month')->first();

        return $row ? [(float) $row->price_per_litre, $row->month] : [null, null];
    }

    public static function priceSetForThisMonth(): bool
    {
        $grades = Car::query()->whereNotNull('fuel_grade')->where('fuel_grade', '!=', 'electric')->distinct()->pluck('fuel_grade');
        if ($grades->isEmpty()) {
            return true;
        }

        return FuelPrice::query()->where('month', now()->startOfMonth()->toDateString())->whereIn('fuel_grade', $grades)->count() >= $grades->count();
    }

    public static function refuelFee(): float
    {
        return round((float) Setting::read('refuel_fee', 0), 2); // decision H-41: Admin decides; empty = no fee
    }

    /* ---------- the charge at return ---------- */

    /**
     * What the customer pays for fuel at return.
     *
     * @return array{amount: ?float, litres: float, missing: int, price: ?float, why: string}
     */
    public static function proposeCharge(Booking $b, ?int $returnEighth): array
    {
        $car = $b->bookable instanceof Car ? $b->bookable : null;
        $out = $returnEighth ?? null;
        $in = self::bookingLevel($b, 'delivery');
        $none = fn (string $why) => ['amount' => null, 'litres' => 0.0, 'missing' => 0, 'price' => null, 'why' => $why];

        if (! $car) {
            return $none('');
        }
        if ($in === null || $out === null) {
            return $none('Pick the fuel level at handover and return to work out a fuel charge.');
        }
        $missing = max(0, $in - $out);
        if ($missing === 0) {
            return $none('Returned with the same fuel as at handover (or more): no fuel charge.');
        }
        if ($car->fuel_grade === 'electric') {
            return $none('Electric car: no fuel charge.');
        }
        if (! $car->tank_litres || ! $car->fuel_grade) {
            return $none('Add the tank size and fuel grade on the car (Cars › '.$car->name.') to work out the fuel charge.');
        }
        [$price, $month] = self::price($car->fuel_grade, $b->returned_at ?? now());
        if ($price === null) {
            return $none('Set the fuel price per litre (Fuel › Fuel prices) to work out the fuel charge.');
        }
        $litres = round($missing / 8 * (float) $car->tank_litres, 1);
        $fee = self::refuelFee();
        $amount = round($litres * $price + $fee, 2);

        return [
            'amount' => $amount,
            'litres' => $litres,
            'missing' => $missing,
            'price' => $price,
            'why' => $missing.'/8 of the tank missing = '.$litres.' L × AED '.number_format($price, 2).' ('.self::GRADES[$car->fuel_grade].', '.$month->format('M Y').')'
                .($fee > 0 ? ' + AED '.number_format($fee, 2).' refuel fee' : '').' = AED '.number_format($amount, 2),
        ];
    }

    /* ---------- fill-ups ---------- */

    /** The booking the car was out on at that moment (between real handover and return), if any. */
    public static function bookingAt(int $carId, $at): ?Booking
    {
        $at = Carbon::parse($at);

        return Booking::query()->where('bookable_type', 'car')->where('bookable_id', $carId)
            ->whereNotNull('delivered_at')->where('delivered_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '>=', $at))
            ->orderByDesc('delivered_at')->first();
    }

    /**
     * Saves a fill-up. From Accounts / Admin it is confirmed at once (the expense is made); from
     * anyone else (a driver, an agent) it waits for Accounts, who are told.
     */
    public static function record(array $data, User $by): FuelEntry
    {
        $car = Car::withTrashed()->find($data['car_id'] ?? null);
        if (! $car) {
            throw ValidationException::withMessages(['data.car_id' => __('driver.fuel_pick_car')]);
        }
        $litres = round((float) ($data['litres'] ?? 0), 2);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($litres <= 0 || $litres > 500) {
            throw ValidationException::withMessages(['data.litres' => __('driver.fuel_litres_needed')]);
        }
        if ($amount <= 0 || $amount > 20000) {
            throw ValidationException::withMessages(['data.amount' => __('driver.fuel_amount_needed')]);
        }
        if (! empty($data['client_id']) && ($same = FuelEntry::query()->where('client_id', $data['client_id'])->first())) {
            return $same; // the phone sent it twice
        }
        $at = Carbon::parse($data['filled_at'] ?? now());
        if ($at->isFuture()) {
            $at = now();
        }
        // The receipt photo: only a fresh upload, moved out of tmp so the clean-up never removes it.
        $receipt = null;
        $upload = is_array($data['receipt'] ?? null) ? (array_values($data['receipt'])[0] ?? null) : ($data['receipt'] ?? null);
        if ($upload && ($fresh = \App\Support\PrivateFiles::freshUploads([$upload], 'tmp'))) {
            $receipt = 'fuel/'.now()->format('Y-m').'/'.basename($fresh[0]);
            \Illuminate\Support\Facades\Storage::disk('private')->move($fresh[0], $receipt);
        }
        if (! $receipt && (bool) Setting::read('fuel_receipt_required', true) && $by->isDriverOnly()) {
            throw ValidationException::withMessages(['data.receipt' => __('driver.fuel_receipt_needed')]);
        }
        $booking = ! empty($data['booking_id']) ? Booking::find($data['booking_id']) : self::bookingAt($car->id, $at);

        $entry = FuelEntry::create([
            'car_id' => $car->id,
            'filled_at' => $at,
            'km' => isset($data['km']) && $data['km'] !== '' ? (int) $data['km'] : null,
            'litres' => $litres,
            'amount' => $amount,
            'fuel_grade' => $data['fuel_grade'] ?? $car->fuel_grade,
            'station' => filled($data['station'] ?? null) ? mb_substr(trim($data['station']), 0, 120) : null,
            'full_tank' => (bool) ($data['full_tank'] ?? true),
            'receipt_path' => $receipt,
            'user_id' => $by->id,
            'booking_id' => $booking?->id,
            'booking_job_id' => $data['booking_job_id'] ?? null,
            'status' => 'waiting',
            'client_id' => $data['client_id'] ?? null,
            'notes' => filled($data['notes'] ?? null) ? mb_substr((string) $data['notes'], 0, 500) : null,
        ]);

        if ($by->can('manage-finance')) {
            self::confirm($entry, $by);
        } else {
            $url = \App\Filament\Resources\FuelEntryResource::getUrl('index', panel: 'admin');
            DB::afterCommit(fn () => Notify::staff('manage-finance', 'Fuel fill-up to confirm: '.$car->name,
                $litres.' L · AED '.number_format($amount, 2).' · by '.$by->name, $url, null, 'info', 'fuel_to_confirm', 'fuel_to_confirm:'.$entry->id,
                [['confirmFuel', 'Confirm', 'rp-confirm-fuel', ['entry' => $entry->id], 'success']]));
        }

        return $entry->fresh();
    }

    /** Accounts confirms: the fuel expense is created on the car (and booking), the car's level and km follow. */
    public static function confirm(FuelEntry $entry, User $by): FuelEntry
    {
        if (! $by->can('manage-finance')) {
            throw ValidationException::withMessages(['entry' => 'Only Accounts or Admin confirm fill-ups.']);
        }
        if ($entry->status === 'confirmed') {
            return $entry;
        }

        return DB::transaction(function () use ($entry, $by) {
            $entry = FuelEntry::query()->lockForUpdate()->find($entry->id);
            if ($entry->status === 'confirmed') {
                return $entry;
            }
            $car = $entry->car;
            $t = Transaction::create([
                'type' => TransactionType::Expense,
                'category' => 'fuel',
                'amount' => $entry->amount,
                'date' => $entry->filled_at->toDateString(),
                'status' => PaymentStatus::Paid,
                'approval_status' => 'approved',
                'approved_by' => $by->id,
                'approved_at' => now(),
                'booking_id' => $entry->booking_id,
                'related_type' => 'car',
                'related_id' => $entry->car_id,
                'vendor' => $entry->station,
                'description' => 'Fuel · '.rtrim(rtrim(number_format((float) $entry->litres, 2), '0'), '.').' L'.($entry->fuel_grade ? ' · '.(self::GRADES[$entry->fuel_grade] ?? $entry->fuel_grade) : '').' · '.$car?->name,
            ]);
            if ($entry->receipt_path && \Illuminate\Support\Facades\Storage::disk('private')->exists($entry->receipt_path)) {
                rescue(fn () => $t->addMediaFromDisk($entry->receipt_path, 'private')->preservingOriginal()->toMediaCollection('receipts'), report: false);
            }
            $entry->forceFill(['status' => 'confirmed', 'confirmed_by' => $by->id, 'confirmed_at' => now(), 'transaction_id' => $t->id, 'reject_reason' => null])->save();

            if ($car) {
                if ($entry->full_tank && (! $car->fuel_level_at || $entry->filled_at->gte($car->fuel_level_at))) {
                    self::setCarLevel($car, 8, $entry->filled_at);
                }
                if ($entry->km && $entry->km > (int) $car->mileage) {
                    $car->forceFill(['mileage' => $entry->km])->saveQuietly();
                }
            }
            if ($entry->user_id && $entry->user_id !== $by->id) {
                $who = User::find($entry->user_id);
                DB::afterCommit(fn () => $who?->isDriverOnly()
                    ? Notify::driver($who, __('driver.fuel_confirmed', [], \App\Support\Drivers\DriverLang::for($who)), $car?->name, null, 'success', 'other', 'fuel_confirmed:'.$entry->id)
                    : Notify::person($who, 'Fill-up confirmed: '.$car?->name, null, null, 'success', 'other', 'fuel_confirmed:'.$entry->id));
            }

            return $entry->fresh();
        });
    }

    public static function reject(FuelEntry $entry, string $reason, User $by): FuelEntry
    {
        if (! $by->can('manage-finance')) {
            throw ValidationException::withMessages(['entry' => 'Only Accounts or Admin decide on fill-ups.']);
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Please say why.']);
        }
        if ($entry->status === 'confirmed') {
            throw ValidationException::withMessages(['entry' => 'Already confirmed: reverse the expense in Accounts instead.']);
        }
        $entry->forceFill(['status' => 'rejected', 'confirmed_by' => $by->id, 'confirmed_at' => now(), 'reject_reason' => mb_substr($reason, 0, 255)])->save();
        if ($entry->user_id && ($who = User::find($entry->user_id)) && ! $who->is($by)) {
            $who->isDriverOnly()
                ? Notify::driver($who, __('driver.fuel_rejected', [], \App\Support\Drivers\DriverLang::for($who)), $reason, null, 'warning', 'other', 'fuel_rejected:'.$entry->id)
                : Notify::person($who, 'Fill-up not accepted: '.$entry->car?->name, $reason, null, 'warning', 'other', 'fuel_rejected:'.$entry->id);
        }

        return $entry->fresh();
    }

    /* ---------- figures ---------- */

    /**
     * km per litre from full tank to full tank: the km since the last full fill-up, divided by the
     * litres put in since then. Only confirmed fill-ups with an odometer count.
     *
     * @return list<array{at: Carbon, km_per_litre: float}>
     */
    public static function consumption(Car $car, int $limit = 20): array
    {
        $rows = FuelEntry::query()->where('car_id', $car->id)->where('status', 'confirmed')->whereNotNull('km')
            ->orderBy('filled_at')->orderBy('id')->get(['filled_at', 'km', 'litres', 'full_tank']);
        $out = [];
        $lastFull = null;
        $litres = 0.0;
        foreach ($rows as $r) {
            $litres += (float) $r->litres;
            if ($r->full_tank) {
                if ($lastFull && $r->km > $lastFull->km && $litres > 0) {
                    $out[] = ['at' => $r->filled_at, 'km_per_litre' => round(($r->km - $lastFull->km) / $litres, 2)];
                }
                $lastFull = $r;
                $litres = 0.0;
            }
        }

        return array_slice($out, -$limit);
    }

    /** @return array{month_litres: float, month_spend: float, km_per_litre: ?float, last_km_per_litre: ?float, cost_per_km: ?float, warning: ?string, waiting: int} */
    public static function stats(Car $car): array
    {
        $month = FuelEntry::query()->where('car_id', $car->id)->where('status', 'confirmed')
            ->whereBetween('filled_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $c = collect(self::consumption($car));
        $avg = $c->count() >= 2 ? round($c->slice(0, -1)->avg('km_per_litre'), 2) : null;
        $last = $c->last()['km_per_litre'] ?? null;
        $kml = $c->isNotEmpty() ? round($c->avg('km_per_litre'), 2) : null;
        [$price] = self::price($car->fuel_grade);
        $pct = (float) Setting::read('fuel_warning_percent', 25);
        $warning = $avg && $last && $pct > 0 && $last < $avg * (1 - $pct / 100)
            ? 'The last fill-up gave '.$last.' km/L, much worse than this car\'s usual '.$avg.' km/L. Check for a leak, misuse or a wrong receipt.'
            : null;

        return [
            'month_litres' => round((float) (clone $month)->sum('litres'), 1),
            'month_spend' => round((float) (clone $month)->sum('amount'), 2),
            'km_per_litre' => $kml,
            'last_km_per_litre' => $last,
            'cost_per_km' => $kml && $price ? round($price / $kml, 2) : null,
            'warning' => $warning,
            'waiting' => FuelEntry::query()->where('car_id', $car->id)->where('status', 'waiting')->count(),
        ];
    }

    /**
     * Per car for a month: litres, spend, km driven (by odometer), km per litre, cost per km,
     * fuel charged to customers on returns that month, and the difference.
     *
     * @return list<array<string, mixed>>
     */
    public static function report(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $entries = FuelEntry::query()->with('car')->where('status', 'confirmed')->whereBetween('filled_at', [$from, $to])->get()->groupBy('car_id');
        $charged = Booking::query()->where('bookable_type', 'car')->whereBetween('returned_at', [$from, $to])->get(['bookable_id', 'charges'])
            ->groupBy('bookable_id')->map(fn (Collection $b) => round($b->sum(fn ($x) => (float) (($x->charges ?? [])['fuel'] ?? 0)), 2));
        $carIds = $entries->keys()->merge($charged->filter(fn ($v) => $v > 0)->keys())->unique();

        return Car::withTrashed()->whereIn('id', $carIds)->orderBy('name')->get()->map(function (Car $car) use ($entries, $charged) {
            $rows = $entries->get($car->id, collect());
            $litres = round((float) $rows->sum('litres'), 1);
            $spend = round((float) $rows->sum('amount'), 2);
            // km between the first and last odometer of the month, and the fuel put in after the
            // first reading (the first fill-up's litres were used before the month's first km).
            $withKm = $rows->filter(fn ($r) => $r->km)->sortBy('filled_at')->values();
            $km = $withKm->count() >= 2 ? (int) ($withKm->max('km') - $withKm->min('km')) : null;
            $after = $km ? $withKm->slice(1) : collect();
            $kml = $km && $after->sum('litres') > 0 ? round($km / (float) $after->sum('litres'), 2) : null;
            $costKm = $km ? round((float) $after->sum('amount') / $km, 2) : null;
            $in = (float) $charged->get($car->id, 0);

            return [
                'car' => $car,
                'fills' => $rows->count(),
                'litres' => $litres,
                'spend' => $spend,
                'km' => $km,
                'km_per_litre' => $kml,
                'cost_per_km' => $costKm,
                'charged' => $in,
                'difference' => round($in - $spend, 2),
            ];
        })->values()->all();
    }

    /** Reminder on the 1st: this month's fuel prices (UAE prices change every month). */
    public static function remindPrices(): bool
    {
        if (self::priceSetForThisMonth()) {
            return false;
        }
        Notify::staff('manage-finance', 'Update this month\'s fuel prices', 'UAE fuel prices change every month. Fuel › Fuel prices.',
            \App\Filament\Resources\FuelEntryResource::getUrl('index', panel: 'admin'), null, 'warning', 'fuel_prices', 'fuel_prices:'.now()->format('Y-m-d'));

        return true;
    }

    /** Who may record a fill-up for a car (drivers: cars they drive or have a job with; office: all). */
    public static function carsFor(User $user): Collection
    {
        if (! $user->isDriverOnly()) {
            return Car::query()->orderBy('name')->get();
        }
        $jobCars = \App\Models\BookingJob::query()->where('booking_jobs.driver_id', $user->id)
            ->whereNotIn('booking_jobs.status', ['cancelled', 'declined'])
            ->where('booking_jobs.scheduled_at', '>=', now()->subDays(2))->where('booking_jobs.scheduled_at', '<=', now()->addDays(2))
            ->join('bookings', 'bookings.id', '=', 'booking_jobs.booking_id')->where('bookings.bookable_type', 'car')
            ->pluck('bookings.bookable_id');
        $own = DB::table('car_driver')->where('user_id', $user->id)->pluck('car_id');

        return Car::query()->whereIn('id', $jobCars->merge($own)->unique())->orderBy('name')->get();
    }
}
