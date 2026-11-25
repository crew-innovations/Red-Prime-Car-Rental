<?php

namespace App\Support\Contracts;

use App\Enums\BookingStatus;
use App\Enums\CustomerType;
use App\Enums\ServiceType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\ContractDriver;
use App\Models\ContractVehicleSwap;
use App\Models\ContractVersion;
use App\Models\RentalContract;
use App\Models\Setting;
use App\Support\Availability;
use App\Support\Documents\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 18 Nov 2026 (meeting item 1, Bilal: "same as on fleet"): rental contracts.
 *
 *  - made from a car booking (one contract per booking), as a draft;
 *  - the customer is the main driver; additional drivers are added with their licence;
 *  - signed in the office, on the driver's phone at handover, or through a link;
 *  - open while the car is out: extend, replace the car, damage OUT / IN, attachments;
 *  - closed after the car is received: the closing summary (km, fuel, charges, money, deposit);
 *  - every change saves a new PDF version ("rental agreement history").
 *
 * Prices, payments, handover, return, fines, tolls and the deposit stay where they are (the
 * booking); the contract shows them and never keeps a second copy of the money.
 */
class Contracts
{
    /** Make the contract of a car booking (draft, version 1). */
    public static function make(Booking $booking, array $data = []): RentalContract
    {
        if ($booking->service_type !== ServiceType::Car) {
            throw ValidationException::withMessages(['contract' => 'Rental contracts are for car rentals.']);
        }
        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            throw ValidationException::withMessages(['contract' => 'This booking is cancelled.']);
        }
        if ($existing = RentalContract::where('booking_id', $booking->id)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($booking, $data) {
            $customer = $booking->customer;
            $monthly = in_array($booking->rental_term, ['long', 'lease'], true);
            $type = $data['type'] ?? match (true) {
                $monthly => 'monthly',
                $customer && $customer->type !== CustomerType::Individual => 'company',
                default => 'individual',
            };
            $contract = RentalContract::create([
                'booking_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                'type' => $type,
                'status' => $booking->status === BookingStatus::InProgress ? 'open' : ($booking->status === BookingStatus::Completed ? 'closed' : 'draft'),
                'billing_period' => $data['billing_period'] ?? ($type === 'monthly' ? 'monthly' : 'daily'),
                'auto_renew' => (bool) ($data['auto_renew'] ?? false),
                'lpo_number' => $data['lpo_number'] ?? null,
                'company_contact' => $data['company_contact'] ?? null,
                'notes' => $data['notes'] ?? null,
                'card_last4' => self::normalizeCardLast4($data['card_last4'] ?? null),
                'card_brand' => filled($data['card_brand'] ?? null) ? mb_substr(trim((string) $data['card_brand']), 0, 40) : null,
                'card_expires_on' => filled($data['card_expires_on'] ?? null) ? mb_substr(trim((string) $data['card_expires_on']), 0, 7) : null,
                'created_by' => auth()->id(),
            ]);
            if ($contract->billing_period === 'monthly') {
                $contract->forceFill(['next_invoice_on' => ContractInvoices::firstInvoiceDate($contract)])->saveQuietly();
            }
            self::syncMainDriver($contract);
            AuditLog::note($booking, 'contract', 'Rental contract '.$contract->number.' made');
            self::savePdf($contract, 'Contract made');

            return $contract->fresh();
        });
    }

    /**
     * 19 Nov (suggestion 6): a paper contract from before the CRM. Makes a completed booking (it holds no
     * car) with the agreed total, km and payment, and a closed contract with the scanned copy.
     */
    public static function enterOld(array $d): RentalContract
    {
        $start = Carbon::parse($d['start_at']);
        $end = Carbon::parse($d['end_at']);
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_at' => 'The return must be after the pick-up.']);
        }

        return DB::transaction(function () use ($d, $start, $end) {
            $booking = Booking::create([
                'service_type' => ServiceType::Car,
                'customer_id' => $d['customer_id'],
                'bookable_id' => $d['car_id'],
                'drive_mode' => $d['drive_mode'] ?? 'self_drive',
                'status' => BookingStatus::Completed,
                'start_at' => $start, 'end_at' => $end,
                'delivered_at' => $start, 'returned_at' => $end,
                'delivery_km' => $d['km_out'] ?? null, 'return_km' => $d['km_in'] ?? null,
                'base_amount' => round((float) $d['total'], 2),
                'security_deposit' => 0,
                'adjustment_reason' => 'Old paper contract '.($d['paper_number'] ?? '').' entered by hand',
                'notes' => $d['notes'] ?? null,
            ]);
            if (($paid = (float) ($d['paid'] ?? 0)) > 0) {
                $t = $booking->recordPayment($paid, $d['method'] ?? 'cash', null, 'Paid on paper contract '.($d['paper_number'] ?? ''));
                $t->forceFill(['date' => $end->toDateString()])->saveQuietly();
                $booking->syncBalance();
            }
            $contract = self::make($booking->fresh(), ['notes' => 'Entered from a paper contract.']);
            $contract->forceFill(['paper_number' => $d['paper_number'] ?? null, 'closed_at' => $end, 'closed_by' => auth()->id(), 'closing' => self::summary($contract)])->save();
            foreach (\App\Support\PrivateFiles::freshUploads((array) ($d['scan'] ?? []), 'contract-files') as $path) {
                $contract->addMediaFromDisk($path, 'private')->toMediaCollection('attachments');
            }
            AuditLog::note($contract, 'contract', 'Old paper contract '.($d['paper_number'] ?? '').' entered');

            return $contract->fresh();
        });
    }

    /** The customer is the main driver; their details are copied onto the contract. */
    public static function syncMainDriver(RentalContract $contract): void
    {
        $c = $contract->customer;
        if (! $c) {
            return;
        }
        $contract->drivers()->updateOrCreate(['is_main' => true], [
            'name' => $c->name,
            'phone' => $c->phone,
            'nationality' => $c->nationality,
            'date_of_birth' => $c->date_of_birth,
            'id_number' => $c->id_number ?: $c->passport_number,
            'id_issued_on' => $c->id_number ? $c->id_issued_on : $c->passport_issued_on,
            'id_expires_on' => $c->id_number ? $c->id_expires_on : $c->passport_expires_on,
            'licence_number' => $c->licence_number,
            'licence_country' => $c->licence_country,
            'licence_place_of_issue' => $c->licence_place_of_issue,
            'licence_issued_on' => $c->licence_issued_on,
            'licence_expires_on' => $c->licence_expires_on,
        ]);
    }

    /** Add an additional driver; a charge goes on the booking as its own line. */
    public static function addDriver(RentalContract $contract, array $data): ContractDriver
    {
        self::assertEditable($contract);
        $booking = $contract->booking;
        if (! empty($data['licence_expires_on']) && $booking?->end_at && Carbon::parse($data['licence_expires_on'])->lt($booking->end_at)) {
            throw ValidationException::withMessages(['licence_expires_on' => 'This licence expires before the car comes back ('.$booking->end_at->format('d M Y').').']);
        }
        if (($min = (int) Setting::read('driver_min_age')) > 0 && ! empty($data['date_of_birth'])
            && Carbon::parse($data['date_of_birth'])->diffInYears($booking?->start_at ?? now()) < $min) {
            throw ValidationException::withMessages(['date_of_birth' => "The minimum driver age is {$min}."]);
        }

        return DB::transaction(function () use ($contract, $booking, $data) {
            $driver = $contract->drivers()->create(collect($data)->only([
                'name', 'phone', 'nationality', 'date_of_birth', 'id_number', 'id_issued_on', 'id_expires_on',
                'licence_number', 'licence_country', 'licence_place_of_issue', 'licence_issued_on', 'licence_expires_on', 'charge',
            ])->all() + ['is_main' => false]);
            if ((float) $driver->charge > 0 && $booking) {
                $extras = (array) ($booking->extras ?? []);
                $extras[] = ['label' => 'Additional driver: '.$driver->name, 'amount' => round((float) $driver->charge, 2), 'kind' => 'manual'];
                $booking->extras = $extras;
                $booking->save();
            }
            self::savePdf($contract, 'Additional driver added: '.$driver->name);

            return $driver;
        });
    }

    public static function removeDriver(RentalContract $contract, ContractDriver $driver, string $reason): void
    {
        self::assertEditable($contract);
        abort_if($driver->is_main || $driver->rental_contract_id !== $contract->id, 403);
        DB::transaction(function () use ($contract, $driver, $reason) {
            $booking = $contract->booking;
            if ($booking && (float) $driver->charge > 0) {
                $label = 'Additional driver: '.$driver->name;
                $booking->extras = collect((array) ($booking->extras ?? []))->reject(fn ($l) => blank($l['key'] ?? null) && ($l['label'] ?? '') === $label)->values()->all();
                $booking->adjustment_reason = $booking->adjustment_reason ?: 'Additional driver removed: '.$reason;
                $booking->save();
            }
            $driver->delete();
            self::savePdf($contract, 'Additional driver removed: '.$driver->name.' ('.$reason.')');
        });
    }

    /** The link the customer opens to read and sign the contract. */
    public static function signLink(RentalContract $contract): string
    {
        if (! $contract->sign_token || ! $contract->sign_token_expires_at || $contract->sign_token_expires_at->isPast()) {
            $contract->forceFill([
                'sign_token' => Str::random(48),
                'sign_token_expires_at' => now()->addHours(max(1, (int) Setting::read('contract_sign_link_hours', 48))),
            ])->saveQuietly();
        }

        return route('contracts.sign', $contract->sign_token);
    }

    /** The customer (hirer) signed (office tablet, driver's phone or the link). */
    public static function sign(RentalContract $contract, string $png, ?string $name, string $via): void
    {
        if ($contract->signed_at) {
            throw ValidationException::withMessages(['signature' => 'This contract is already signed by the hirer.']);
        }
        if (! in_array($contract->status, ['draft', 'open'], true)) {
            throw ValidationException::withMessages(['signature' => 'This contract can no longer be signed.']);
        }
        if ($missing = self::missingForSign($contract)) {
            throw ValidationException::withMessages(['signature' => 'Still needed before signing: '.implode(', ', $missing).'.']);
        }
        DB::transaction(function () use ($contract, $png, $name, $via) {
            $contract->addMediaFromString($png)->usingFileName('signature-'.$contract->id.'-'.now()->format('YmdHis').'.png')
                ->usingName('Customer signature'.($name ? ': '.$name : ''))->toMediaCollection('signature');
            $contract->forceFill([
                'signed_at' => now(),
                'signed_by_name' => $name ? mb_substr(trim($name), 0, 120) : $contract->customer?->name,
                'signed_via' => mb_substr($via, 0, 12),
                'sign_token' => null,
                'sign_token_expires_at' => null,
                // The terms as they were when signed, so a later change in settings does not change this contract.
                'terms_en' => filled($contract->terms_en) ? $contract->terms_en : (string) Setting::read('contract_terms_en'),
                'terms_ar' => filled($contract->terms_ar) ? $contract->terms_ar : (string) Setting::read('contract_terms_ar'),
                'terms_ur' => filled($contract->terms_ur) ? $contract->terms_ur : (string) Setting::read('contract_terms_ur'),
            ])->save();
            self::savePdf($contract, 'Signed by the hirer ('.$via.')');
        });
    }

    /**
     * 25 Nov: the CRM driver signs on their phone (My jobs). Independent of the hirer signature;
     * the PDF regenerates after each. Fully signed = both.
     */
    public static function signDriver(RentalContract $contract, string $png, ?string $name, string $via = 'driver'): void
    {
        if ($contract->driver_signed_at) {
            throw ValidationException::withMessages(['signature' => 'This contract already has the driver\'s signature.']);
        }
        if (! in_array($contract->status, ['draft', 'open'], true)) {
            throw ValidationException::withMessages(['signature' => 'This contract can no longer be signed.']);
        }
        DB::transaction(function () use ($contract, $png, $name, $via) {
            $contract->addMediaFromString($png)->usingFileName('driver-signature-'.$contract->id.'-'.now()->format('YmdHis').'.png')
                ->usingName('Driver signature'.($name ? ': '.$name : ''))->toMediaCollection('driver_signature');
            $contract->forceFill([
                'driver_signed_at' => now(),
                'driver_signed_by_name' => $name ? mb_substr(trim($name), 0, 120) : (auth()->user()?->name),
                'driver_signed_via' => mb_substr($via, 0, 24),
                'terms_en' => filled($contract->terms_en) ? $contract->terms_en : (string) Setting::read('contract_terms_en'),
                'terms_ar' => filled($contract->terms_ar) ? $contract->terms_ar : (string) Setting::read('contract_terms_ar'),
                'terms_ur' => filled($contract->terms_ur) ? $contract->terms_ur : (string) Setting::read('contract_terms_ur'),
            ])->save();
            self::savePdf($contract, 'Signed by the driver ('.$via.')');
        });
    }

    public static function fullySigned(RentalContract $contract): bool
    {
        return $contract->isFullySigned();
    }

    /** Save deposit card reference (last 4 only — never a full card number). */
    public static function saveCard(RentalContract $contract, array $data): void
    {
        self::assertEditable($contract);
        $last4 = self::normalizeCardLast4($data['card_last4'] ?? null);
        if ($last4 === null && filled($data['card_last4'] ?? null)) {
            throw ValidationException::withMessages(['card_last4' => 'Enter the last 4 digits of the card only.']);
        }
        $contract->forceFill([
            'card_last4' => $last4,
            'card_brand' => filled($data['card_brand'] ?? null) ? mb_substr(trim((string) $data['card_brand']), 0, 40) : null,
            'card_expires_on' => filled($data['card_expires_on'] ?? null) ? mb_substr(trim((string) $data['card_expires_on']), 0, 7) : null,
        ])->save();
        self::savePdf($contract, 'Card details updated');
    }

    protected static function normalizeCardLast4(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) > 4) {
            $digits = substr($digits, -4);
        }

        return strlen($digits) === 4 ? $digits : null;
    }

    /**
     * Fields the reference PDF needs before the hirer can sign. Empty = ready.
     * Self-drive needs the main driver's licence block; with-driver still needs the hirer block.
     *
     * @return list<string>
     */
    public static function missingForSign(RentalContract $contract): array
    {
        $out = [];
        $b = $contract->booking;
        $cust = $contract->customer;
        $car = $b?->bookable;
        $main = $contract->mainDriver();
        $selfDrive = $b?->drive_mode !== \App\Enums\DriveMode::WithDriver;

        if (! $cust) {
            $out[] = 'Hirer (customer)';
        } else {
            foreach (['name' => 'Hirer name', 'nationality' => 'Hirer nationality', 'phone' => 'Hirer mobile', 'address' => 'Hirer address'] as $f => $label) {
                if (blank($cust->{$f})) {
                    $out[] = $label;
                }
            }
            $hasId = filled($cust->id_number);
            $hasPass = filled($cust->passport_number);
            if (! $hasId && ! $hasPass) {
                $out[] = 'Hirer passport or ID number';
            } elseif ($hasId) {
                if (! $cust->id_issued_on) {
                    $out[] = 'Hirer ID issue date';
                }
                if (! $cust->id_expires_on) {
                    $out[] = 'Hirer ID expiry';
                }
            } else {
                if (! $cust->passport_issued_on) {
                    $out[] = 'Hirer passport issue date';
                }
                if (! $cust->passport_expires_on) {
                    $out[] = 'Hirer passport expiry';
                }
            }
        }

        if ($selfDrive) {
            if (blank($main?->licence_number) && blank($cust?->licence_number)) {
                $out[] = 'Driving licence number';
            }
            if (blank($main?->licence_place_of_issue) && blank($cust?->licence_place_of_issue)) {
                $out[] = 'Licence place of issue';
            }
            if (! ($main?->licence_issued_on ?? $cust?->licence_issued_on)) {
                $out[] = 'Licence issue date';
            }
            if (! ($main?->licence_expires_on ?? $cust?->licence_expires_on)) {
                $out[] = 'Licence expiry';
            }
        }

        if (! $car instanceof Car) {
            $out[] = 'Vehicle';
        } else {
            if (blank($car->registration_number)) {
                $out[] = 'Plate number';
            }
            if (blank($car->name) && blank($car->type)) {
                $out[] = 'Vehicle type';
            }
            if (blank($car->colour)) {
                $out[] = 'Vehicle colour';
            }
            if (blank($car->year) && blank($car->model) && blank($car->brand)) {
                $out[] = 'Vehicle model / year';
            }
        }

        if (! $b?->start_at || ! $b?->end_at) {
            $out[] = 'Rental period';
        }

        if (blank($contract->card_last4)) {
            $out[] = 'Card last 4 digits (deposit reference)';
        }

        return array_values(array_unique($out));
    }

    /**
     * Charge sheet for the reference PDF (Shahem-style).
     *
     * @return array{base: float, discount: float, days: int, advance: float, fines: float, deposit: float, extras: float, total: float, paid: float, balance: float}
     */
    public static function chargeSheet(RentalContract $contract): array
    {
        $b = $contract->booking;
        $days = 1;
        if ($b?->start_at && $b?->end_at) {
            $days = max(1, (int) ceil($b->start_at->diffInMinutes($b->end_at) / 1440));
        }
        $fines = 0.0;
        if ($b) {
            $fines = (float) \App\Models\TrafficFine::query()->where('booking_id', $b->id)->sum('amount');
        }
        $extras = 0.0;
        if ($b) {
            foreach ((array) ($b->extras ?? []) as $line) {
                $extras += (float) ($line['amount'] ?? 0);
            }
            foreach ((array) ($b->charges ?? []) as $v) {
                $extras += (float) $v;
            }
        }

        return [
            'base' => round((float) ($b?->base_amount ?? $b?->total ?? 0), 2),
            'discount' => round((float) ($b?->discount ?? 0), 2),
            'days' => $days,
            'advance' => round((float) ($b?->paid_amount ?? 0), 2),
            'fines' => round($fines, 2),
            'deposit' => $b?->deposit_mode === 'none' ? 0.0 : round((float) ($b?->security_deposit ?? 0), 2),
            'extras' => round($extras, 2),
            'total' => round((float) ($b?->amountDue() ?? $b?->total ?? 0), 2),
            'paid' => round((float) ($b?->paid_amount ?? 0), 2),
            'balance' => round((float) ($b?->balance ?? 0), 2),
        ];
    }

    /** The car went out: the contract is open. */
    public static function onHandover(Booking $booking): void
    {
        $c = RentalContract::where('booking_id', $booking->id)->first();
        if ($c && $c->status === 'draft') {
            $c->forceFill(['status' => 'open'])->save();
            self::savePdf($c, 'Car handed over');
        }
    }

    public static function onCancelled(Booking $booking): void
    {
        $c = RentalContract::where('booking_id', $booking->id)->first();
        if ($c && in_array($c->status, ['draft', 'open'], true)) {
            $c->forceFill(['status' => 'cancelled'])->save();
            AuditLog::note($c, 'status', 'Cancelled with booking '.$booking->reference);
        }
    }

    /** Extend the rental: the booking's return moves (price worked out again, car checked). */
    public static function extend(RentalContract $contract, string $newEnd, string $reason): void
    {
        self::assertEditable($contract);
        $booking = $contract->booking;
        $end = Carbon::parse($newEnd);
        if (! $booking || $end->lte($booking->end_at)) {
            throw ValidationException::withMessages(['end_at' => 'Pick a return after the current one ('.$booking?->end_at?->format('d M Y H:i').').']);
        }
        DB::transaction(function () use ($contract, $booking, $end, $reason) {
            $old = $booking->end_at->copy();
            $booking->end_at = $end;
            $booking->save();
            AuditLog::note($booking, 'contract', 'Extended from '.$old->format('d M Y H:i').' to '.$end->format('d M Y H:i').': '.$reason);
            self::savePdf($contract, 'Extended to '.$end->format('d M Y H:i').' ('.$reason.')');
        });
    }

    /**
     * Replace the car while the contract is open: the first car comes back (km, fuel), the new
     * car goes out (km, fuel); the booking moves to the new car. Fines and Salik are matched to
     * the right car by time, because each swap keeps its time.
     */
    public static function swap(RentalContract $contract, array $data): ContractVehicleSwap
    {
        self::assertEditable($contract);
        $booking = $contract->booking;
        $from = $booking?->bookable;
        $to = Car::find($data['to_car_id'] ?? null);
        if (! $booking || ! $from instanceof Car || ! $to || $to->id === $from->id) {
            throw ValidationException::withMessages(['to_car_id' => 'Pick another car.']);
        }
        $at = Carbon::parse($data['swapped_at'] ?? now());
        if ($msg = Availability::assetConflict('car', $to->id, $at, $booking->end_at, $booking->id)) {
            throw ValidationException::withMessages(['to_car_id' => $msg]);
        }
        if ($restriction = Availability::restriction('car', $to->id)) {
            throw ValidationException::withMessages(['to_car_id' => $restriction]);
        }

        return DB::transaction(function () use ($contract, $booking, $from, $to, $at, $data) {
            $swap = $contract->swaps()->create([
                'from_car_id' => $from->id,
                'to_car_id' => $to->id,
                'swapped_at' => $at,
                'from_km_out' => $booking->delivery_km,
                'from_km_in' => $data['from_km_in'] ?? null,
                'from_fuel_in_8' => $data['from_fuel_in_8'] ?? null,
                'to_km_out' => $data['to_km_out'] ?? null,
                'to_fuel_out_8' => $data['to_fuel_out_8'] ?? null,
                'reason' => mb_substr((string) ($data['reason'] ?? ''), 0, 255),
                'created_by' => auth()->id(),
            ]);
            // The booking now holds the new car; the handover figures are the new car's.
            $booking->forceFill([
                'bookable_id' => $to->id,
                'delivery_km' => $data['to_km_out'] ?? null,
                'delivery_fuel_8' => $data['to_fuel_out_8'] ?? null,
                'delivery_fuel' => isset($data['to_fuel_out_8']) ? \App\Support\Fuel\Fuel::toQuarter((int) $data['to_fuel_out_8']) : null,
            ]);
            $booking->adjustment_reason = $booking->adjustment_reason ?: 'Car replaced: '.$swap->reason;
            $booking->save();
            if (! empty($data['from_km_in']) && (int) $data['from_km_in'] > (int) $from->mileage) {
                $from->forceFill(['mileage' => (int) $data['from_km_in']])->saveQuietly();
            }
            if (isset($data['from_fuel_in_8'])) {
                \App\Support\Fuel\Fuel::setCarLevel($from, (int) $data['from_fuel_in_8'], $at);
            }
            $from->refreshBookingStatus();
            $to->refreshBookingStatus();
            AuditLog::note($booking, 'contract', 'Car replaced: '.$from->name.' → '.$to->name.' ('.$swap->reason.')');
            self::savePdf($contract, 'Car replaced: '.$from->name.' → '.$to->name);

            return $swap;
        });
    }

    /**
     * What the customer owes at the end, from what the booking already holds: rental, return
     * charges, fines / tolls / fees, km driven (all cars), fuel, payments and the deposit.
     */
    public static function summary(RentalContract $contract): array
    {
        $b = $contract->booking;
        if (! $b) {
            return [];
        }
        $km = 0;
        $legs = [];
        foreach ($contract->swaps as $s) {
            if ($s->from_km_out !== null && $s->from_km_in !== null && $s->from_km_in >= $s->from_km_out) {
                $km += $s->from_km_in - $s->from_km_out;
                $legs[] = ['car' => $s->fromCar?->name, 'out' => $s->from_km_out, 'in' => $s->from_km_in];
            }
        }
        if ($b->delivery_km !== null && $b->return_km !== null && $b->return_km >= $b->delivery_km) {
            $km += $b->return_km - $b->delivery_km;
            $legs[] = ['car' => $b->bookable?->name, 'out' => $b->delivery_km, 'in' => $b->return_km];
        }
        $end = $b->returned_at ?? $b->end_at;
        $days = max(1, (int) ceil($b->start_at->diffInMinutes($end) / 1440));
        $limit = $b->kmLimitPerDay();
        $included = $limit ? $limit * $days : null;
        $extraKm = $included !== null ? max(0, $km - $included) : 0;
        $rate = $b->extraKmRate();

        $charges = collect((array) ($b->charges ?? []))->map(fn ($v, $k) => ['label' => \App\Support\Options::chargeLabel($k), 'amount' => round((float) $v, 2)])->values()->all();
        $lines = $b->chargeLines()->whereNull('voided_at')->get()->map(fn ($l) => ['label' => (string) $l->label, 'amount' => round((float) $l->amount, 2)])->all();

        return [
            'rental' => round((float) $b->total, 2),
            'return_charges' => $charges,
            'other_charges' => $lines,
            'km_legs' => $legs,
            'km_driven' => $legs ? $km : null,
            'days' => $days,
            'km_included' => $included,
            'km_extra' => $extraKm,
            'extra_km_rate' => $rate,
            'extra_km_charge_suggested' => $rate && $extraKm ? round($extraKm * $rate, 2) : 0.0,
            'fuel_out' => $b->delivery_fuel_8,
            'fuel_in' => $b->return_fuel_8,
            'total_due' => $b->amountDue(),
            'paid' => round((float) $b->paid_amount, 2),
            'balance' => round((float) $b->balance, 2),
            'deposit_mode' => $b->deposit_mode,
            'deposit_held' => $b->depositHeld(),
            'deposit_refundable_on' => $b->deposit_refundable_on?->toDateString(),
            'returned_at' => $b->returned_at?->toDateTimeString(),
            'calculated_at' => now()->toDateTimeString(),
        ];
    }

    /** Close after the car is received (Receive car on the booking). */
    public static function close(RentalContract $contract, ?string $note = null): void
    {
        $b = $contract->booking;
        if (! $b || $b->status !== BookingStatus::Completed) {
            throw ValidationException::withMessages(['contract' => 'Receive the car back first (Receive car on the booking), then close the contract.']);
        }
        if ($contract->status === 'closed') {
            return;
        }
        DB::transaction(function () use ($contract, $note) {
            $contract->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'closing' => self::summary($contract),
                'notes' => trim(($contract->notes ? $contract->notes."\n" : '').($note ? 'Closing: '.$note : '')) ?: null,
            ])->save();
            self::savePdf($contract, 'Closed');
        });
    }

    /** Save a new PDF version of the contract. */
    public static function savePdf(RentalContract $contract, string $reason): ?ContractVersion
    {
        $contract->refresh();
        $version = (int) $contract->version + 1;
        $data = ['c' => $contract, 'b' => $contract->booking, 'version' => $version];
        try {
            $bytes = Pdf::available() ? Pdf::render('pdf.contract', $data) : view('pdf.contract', $data + ['asPdf' => false])->render();
        } catch (\Throwable $e) {
            report($e);

            return null; // the contract itself is saved; the PDF can be made again
        }
        $path = 'contracts/'.$contract->id.'/'.$contract->number.'-v'.$version.(Pdf::available() ? '.pdf' : '.html');
        Storage::disk('private')->put($path, $bytes);
        $contract->forceFill(['version' => $version])->saveQuietly();

        return $contract->versions()->create([
            'version' => $version,
            'path' => $path,
            'reason' => mb_substr($reason, 0, 255),
            'created_by' => auth()->id(),
        ]);
    }

    protected static function assertEditable(RentalContract $contract): void
    {
        if (! $contract->isEditable()) {
            throw ValidationException::withMessages(['contract' => 'This contract is '.strtolower($contract->statusLabel()).'.']);
        }
    }
}
