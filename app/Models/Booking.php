<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\DriveMode;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Enums\TransactionType;
use App\Enums\YachtStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPricing;
use App\Support\Availability;
use App\Support\Rules;
use App\Support\Tax;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Booking extends Model implements HasMedia
{
    use Audited, HasPricing, InteractsWithMedia, SoftDeletes;

    protected $guarded = ['id'];

    public const FUEL_LEVELS = [
        'empty' => 'Empty',
        'quarter' => '1/4',
        'half' => '1/2',
        'three_quarter' => '3/4',
        'full' => 'Full',
    ];

    public const CONDITIONS = [
        'good' => 'Good, no new damage',
        'minor' => 'Minor marks / scratches',
        'damaged' => 'Damaged',
    ];


    /** Avoids loops while bookings and their transactions update each other. */
    protected static bool $syncing = false;

    /** 18 Nov: Admin confirmed although details or documents are missing (the "Confirm" button records why). */
    public static bool $confirmOverride = false;

    protected function casts(): array
    {
        return [
            'cancel_requested_at' => 'datetime', // 5 Nov (S3)
            'delivery_fuel_8' => 'integer',      // 5 Nov (S5)
            'return_fuel_8' => 'integer',
            'fuel_charge_proposed' => 'decimal:2',
            'status' => BookingStatus::class,
            'service_type' => ServiceType::class,
            'drive_mode' => DriveMode::class,
            'payment_status' => PaymentStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'hold_until' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'charges' => 'array',
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'extras_amount' => 'decimal:2',
            'extras' => 'array',
            'crew_ids' => 'array',
            'discount' => 'decimal:2',
            'standard_amount' => 'decimal:2',
            'price_breakdown' => 'array',
            // Limousine & transfers (28 Oct-2)
            'stops' => 'array',
            'route' => 'array',
            'return_at' => 'datetime',
            'route_km' => 'decimal:2',
            'return_route_km' => 'decimal:2',
            'route_minutes' => 'integer',
            'waiting_minutes' => 'integer',
            'luggage' => 'integer',
            'child_seats' => 'integer',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'security_deposit' => 'decimal:2',
            'extra_charges' => 'decimal:2',
            'charge_lines_total' => 'decimal:2',
            'pass_through_total' => 'decimal:2',
            'deposit_refundable_on' => 'date',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'supplier_cost' => 'decimal:2',
            'supplier_cost_snapshot' => 'array',
            // 4 Nov (sections 10, 11)
            'base_discount_percent' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'list_discount_amount' => 'decimal:2',
            'list_discount_percent' => 'decimal:2',
            'advance_percent' => 'decimal:2',
            'advance_required' => 'decimal:2',
            'advance_reached_at' => 'datetime',
            // 18 Nov (meeting items 2, 4) and the rental agreement
            'delivery_fee_on' => 'boolean',
            'delivery_fee_amount' => 'decimal:2',
            'collection_fee_on' => 'boolean',
            'collection_fee_amount' => 'decimal:2',
            'no_deposit_fee' => 'decimal:2',
            'km_limit_per_day' => 'integer',
            'extra_km_rate' => 'decimal:2',
            'marketplace_commission' => 'decimal:2', // 19 Nov
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Booking $booking) {
            // Website hold (30 Oct): only while the booking still waits for its first payment.
            if ($booking->hold_until && $booking->status !== BookingStatus::PendingPayment) {
                $booking->hold_until = null;
            }
            $booking->service_type ??= match ($booking->bookable_type) {
                'yacht' => ServiceType::Yacht,
                'trip' => ServiceType::Trip,
                'package' => ServiceType::Package,
                default => ServiceType::Car,
            };
            $isTransfer = $booking->service_type === ServiceType::Transfer;
            if ($booking->bookable_id) {
                // Morph aliases match the service (car / yacht / trip); a transfer's vehicle is a car of the fleet.
                $booking->bookable_type = $isTransfer ? 'car' : $booking->service_type->value;
            }
            if ($booking->service_type !== ServiceType::Car) {
                $booking->drive_mode = null;
            }
            // Any car booking can have a driver: with driver he drives, self-drive he delivers and collects.
            // A transfer's driver (chauffeur) drives it from pickup to drop-off.
            if (! in_array($booking->service_type, [ServiceType::Car, ServiceType::Transfer], true)) {
                $booking->driver_id = null;
            }
            if ($booking->service_type !== ServiceType::Car) {
                $booking->delivery_method = null;
                $booking->return_method = null;
                $booking->deposit_mode = null; // 18 Nov: the deposit choice is for car rentals
                $booking->no_deposit_fee = null;
            }
            // 18 Nov (meeting item 4): "No deposit" means no security deposit is held.
            if ($booking->deposit_mode === 'none') {
                $booking->security_deposit = 0;
            } else {
                $booking->no_deposit_fee = null;
            }
            if ($booking->delivery_method !== 'delivery') {
                $booking->delivery_fee_on = null;
                $booking->delivery_fee_amount = null;
            }
            if ($booking->return_method !== 'collection') {
                $booking->collection_fee_on = null;
                $booking->collection_fee_amount = null;
            }
            \App\Support\Transfers\TransferJourney::prepare($booking);
            // With driver: the same person drives from start to end. Collected by the customer: no collection driver.
            if ($booking->service_type !== ServiceType::Car || $booking->drive_mode === DriveMode::WithDriver || $booking->return_method === 'office'
                || (int) $booking->return_driver_id === (int) $booking->driver_id) {
                $booking->return_driver_id = null;
            }
            // Private yacht charters (28 Oct): with captain / crew, or bareboat only where the yacht allows it.
            if ($booking->service_type === ServiceType::Yacht) {
                $booking->crew_mode ??= 'with_crew';
                if ($booking->crew_mode === 'bareboat') {
                    $yacht = Yacht::withTrashed()->find($booking->bookable_id);
                    if (! $yacht?->bareboat_allowed) {
                        throw ValidationException::withMessages(['data.crew_mode' => 'This yacht is not allowed without crew. Book it with a captain.']);
                    }
                    $booking->captain_id = null;
                    $booking->crew_ids = null;
                }
                $booking->crew_ids = $booking->crew_ids ? array_values(array_unique(array_map('intval', array_diff((array) $booking->crew_ids, [$booking->captain_id])))) : null;
            } else {
                $booking->crew_mode = null;
                $booking->captain_id = null;
                $booking->crew_ids = null;
            }
            // A new booking made from a quotation keeps the agreed price and its calculation
            // as they are; any other new booking or price change is worked out again.
            if (! ($booking->quotation_id && ! $booking->exists && $booking->price_breakdown)) {
                $booking->applyPricing();
            }
            if ($booking->priceBlocked() && in_array($booking->status, BookingStatus::billable(), true)) {
                throw ValidationException::withMessages(['data.status' => $booking->price_approval === 'rejected'
                    ? 'A manager turned this price down ('.$booking->price_rejected_reason.'). Change the price, or save it as an Inquiry.'
                    : 'This price is below the discount limit and waits for a manager\'s OK. Save it as an Inquiry until then.']);
            }
            [$booking->tax_amount, $booking->total] = Tax::apply(
                (float) $booking->base_amount + (float) $booking->extras_amount - (float) $booking->discount,
                $booking->tax_rate !== null ? (float) $booking->tax_rate : null,
                (bool) $booking->tax_inclusive,
            );
            if ($booking->service_type !== ServiceType::Package) {
                $booking->package_departure_id = null;
            }
            // Advance to confirm (4 Nov, 11): default % per service, minimum checked. The amount is set by syncBalance().
            \App\Support\Pricing\Advance::prepare($booking);
            $booking->assigned_to ??= auth()->id();

            // Optional rule (Business settings): confirmed bookings only come from an accepted quotation.
            if (! $booking->exists && ! $booking->quotation_id && auth()->check() && Setting::read('booking_requires_accepted_quote')
                && in_array($booking->status, BookingStatus::billable(), true)) {
                throw ValidationException::withMessages(['data.status' => 'Bookings are made from an accepted quotation. Send a quotation first, or save this as an Inquiry.']);
            }

            // 18 Nov (meeting item 8): confirmed only with the customer's details and documents complete.
            if (\App\Support\Contracts\ConfirmCheck::enabled() && ! static::$confirmOverride && \App\Support\Contracts\ConfirmCheck::isConfirming($booking)
                && ($missing = \App\Support\Contracts\ConfirmCheck::missing($booking))) {
                throw ValidationException::withMessages(['data.status' => 'Before it can be confirmed, complete: '.implode(', ', $missing).'. Save it as Waiting for payment until then.']);
            }

            $booking->guardAvailability();

            // Supplier cost snapshot (29 Oct); stops confirmation while a supplier vehicle has no cost.
            \App\Support\Suppliers\SupplierCost::apply($booking);
        });

        static::created(function (Booking $booking) {
            $booking->forceFill(['reference' => sprintf('BK-%05d', $booking->id)])->saveQuietly();
            // 4 Nov (13): a booking makes a prospect a customer, and gives them an account (setting).
            \App\Support\Portal::onBooking($booking);
        });

        static::saved(function (Booking $booking) {
            $booking->syncBalance();
            $booking->bookable?->refreshBookingStatus();

            // A different car / yacht was picked: free the old one.
            if ($booking->wasChanged(['bookable_type', 'bookable_id']) && $booking->getOriginal('bookable_id')) {
                Relation::getMorphedModel($booking->getOriginal('bookable_type'))::find($booking->getOriginal('bookable_id'))?->refreshBookingStatus();
            }

            $booking->advanceLead();

            \App\Support\Drivers\DriverJobs::sync($booking);

            // 4 Nov (brief 9.3): confirmed, handed over, returned.
            rescue(fn () => \App\Support\Notifications\EventHooks::bookingStatus($booking));

            // Supplier bill (Bilal, 29 Oct): posted once when the job is completed, with the completion.
            if ($booking->wasChanged('status') && $booking->status === BookingStatus::Completed && $booking->supplier_id) {
                \App\Support\Suppliers\SupplierLedger::postForBooking($booking);
            }

            // 18 Nov: the rental contract follows the booking (open when the car goes out, cancelled with it).
            if ($booking->wasChanged('status')) {
                if ($booking->status === BookingStatus::InProgress) {
                    rescue(fn () => \App\Support\Contracts\Contracts::onHandover($booking));
                } elseif (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
                    rescue(fn () => \App\Support\Contracts\Contracts::onCancelled($booking));
                }
            }

            // Job completion report (28 Oct-2, Phase 6): made when the job is completed, after the save is final.
            if ($booking->wasChanged('status') && $booking->status === BookingStatus::Completed) {
                $by = auth()->user();
                \Illuminate\Support\Facades\DB::afterCommit(fn () => rescue(fn () => \App\Support\Documents\JobReports::generate($booking->fresh(), $by, 'Job completed')));
            }
        });

        static::deleted(function (Booking $booking) {
            $booking->transactions()->where('is_balance', true)->get()->each->forceDelete();
            $booking->bookable?->refreshBookingStatus();
            \App\Support\Drivers\DriverJobs::sync($booking);
        });

        static::restored(function (Booking $booking) {
            $booking->syncBalance();
            \App\Support\Drivers\DriverJobs::sync($booking);
        });
    }

    /**
     * Server-side safety net for every save (forms, actions, imports): a car /
     * yacht can't be held twice for the same time, and a trip date can't take
     * more guests than it has seats. Runs inside the save transaction, with the
     * car / yacht / trip-date row locked.
     */
    public function guardAvailability(): void
    {
        $this->guardDriver();
        $this->guardReturnDriver();
        $this->guardCrew();

        $relevant = ! $this->exists || $this->isDirty(['status', 'start_at', 'end_at', 'bookable_type', 'bookable_id', 'trip_date_id', 'package_departure_id', 'guests']);
        if (! $relevant) {
            return;
        }

        $type = $this->service_type?->value;
        $assetType = $type === 'transfer' ? 'car' : $type; // a transfer holds its car like a rental does

        if (Availability::holds($this->status) && in_array($assetType, ['car', 'yacht'], true) && $this->bookable_id) {
            if ($message = Availability::assetConflict($assetType, (int) $this->bookable_id, $this->start_at, $this->end_at, $this->getKey())) {
                throw ValidationException::withMessages(['data.end_at' => $message]);
            }
        }

        if ($type === 'yacht' && $this->bookable_id && $this->guests) {
            $yacht = Yacht::withTrashed()->find($this->bookable_id);
            if ($yacht?->max_guests && (int) $this->guests > $yacht->max_guests) {
                throw ValidationException::withMessages(['data.guests' => "This yacht takes up to {$yacht->max_guests} guests."]);
            }
        }

        if ($type === 'trip' && $this->trip_date_id && in_array($this->status, BookingStatus::billable(), true)) {
            $date = TripDate::with('trip')->find($this->trip_date_id);
            $left = $date ? Availability::seatsLeft($date, $this->getKey()) : null;
            if ($left !== null && (int) $this->guests > $left) {
                throw ValidationException::withMessages(['data.guests' => "Only {$left} seat(s) left on this date."]);
            }
        }

        if ($type === 'package' && $this->package_departure_id && in_array($this->status, BookingStatus::billable(), true)) {
            $departure = PackageDeparture::with('package')->find($this->package_departure_id);
            $left = $departure ? Availability::packageSeatsLeft($departure, $this->getKey()) : null;
            if ($left !== null && (int) $this->guests > $left) {
                throw ValidationException::withMessages(['data.guests' => "Only {$left} place(s) left on this departure."]);
            }
        }
    }

    /**
     * Driver checks on every save: only active drivers can be given new work,
     * optionally only with complete papers, and never two jobs at once.
     */
    public function guardDriver(): void
    {
        if (! $this->driver_id || ! in_array($this->service_type, [ServiceType::Car, ServiceType::Transfer], true)) {
            return;
        }
        if ($this->exists && ! $this->isDirty(['driver_id', 'start_at', 'end_at', 'status', 'drive_mode'])) {
            return;
        }

        $driver = \Illuminate\Support\Facades\DB::transactionLevel() > 0
            ? User::whereKey($this->driver_id)->lockForUpdate()->first()
            : User::find($this->driver_id);

        if (! $this->exists || $this->isDirty('driver_id')) {
            if (! $driver || ! $driver->hasRole('Driver')) {
                throw ValidationException::withMessages(['data.driver_id' => 'Pick a driver from the list.']);
            }
            if (! $driver->is_active) {
                throw ValidationException::withMessages(['data.driver_id' => $driver->name.' is switched off (inactive) and cannot get new jobs.']);
            }
            if ($driver->driverProfile && ! $driver->driverProfile->does('driver')) {
                throw ValidationException::withMessages(['data.driver_id' => $driver->name.' is set up as yacht crew, not as a car driver.']);
            }
            if (Setting::read('driver_block_incomplete') && ($missing = \App\Support\Drivers\DriverPapers::problems($driver))) {
                throw ValidationException::withMessages(['data.driver_id' => $driver->name.' cannot get jobs until these are sorted: '.implode(', ', $missing).'.']);
            }
        }

        $newlyHolding = ! $this->exists
            || $this->isDirty(['driver_id', 'start_at', 'end_at', 'drive_mode'])
            || ! Availability::holds($this->getOriginal('status') instanceof BookingStatus ? $this->getOriginal('status') : BookingStatus::tryFrom((string) $this->getOriginal('status')));
        if ($driver && $newlyHolding && Availability::holds($this->status)) {
            // With a separate collection driver this driver only does the delivery.
            $until = $this->return_driver_id && $this->drive_mode !== DriveMode::WithDriver ? $this->start_at : $this->end_at;
            $mode = $this->service_type === ServiceType::Transfer ? DriveMode::WithDriver : $this->drive_mode; // a chauffeur works the whole transfer
            if ($off = Availability::offTime($driver->id, $this->start_at, $until, $mode)) {
                throw ValidationException::withMessages(['data.driver_id' => $driver->name.' is not available then ('.$off->label().').'
                    .Availability::freeText('driver', $this->start_at, $until, $this->getKey(), $mode, $driver->id)]);
            }
            if ($clash = Availability::personConflict($driver->id, $this->start_at, $until, $this->getKey(), $mode)) {
                throw ValidationException::withMessages(['data.driver_id' => $driver->name.' is already busy on '.$clash->reference.' ('.$clash->start_at->format('d M H:i').' – '.$clash->end_at->format('d M H:i').').'
                    .Availability::freeText('driver', $this->start_at, $until, $this->getKey(), $mode, $driver->id)]);
            }
        }
    }

    /** Collection driver (when not the delivery driver): same checks, at the return time only. */
    public function guardReturnDriver(): void
    {
        if (! $this->return_driver_id || ($this->exists && ! $this->isDirty(['return_driver_id', 'end_at', 'status']))) {
            return;
        }
        $driver = User::with('driverProfile')->find($this->return_driver_id);
        if (! $driver || ! $driver->hasRole('Driver') || ! $driver->is_active || ($driver->driverProfile && ! $driver->driverProfile->does('driver'))) {
            throw ValidationException::withMessages(['data.return_driver_id' => 'Pick an active driver for the collection.']);
        }
        if (Availability::holds($this->status) && ($off = Availability::offTime($driver->id, $this->end_at, $this->end_at, DriveMode::SelfDrive))) {
            throw ValidationException::withMessages(['data.return_driver_id' => $driver->name.' is not available then ('.$off->label().').']);
        }
        if (Availability::holds($this->status) && ($clash = Availability::personConflict($driver->id, $this->end_at, $this->end_at, $this->getKey(), DriveMode::SelfDrive))) {
            throw ValidationException::withMessages(['data.return_driver_id' => $driver->name.' is already busy on '.$clash->reference.' ('.$clash->start_at->format('d M H:i').' – '.$clash->end_at->format('d M H:i').').'
                .Availability::freeText('driver', $this->end_at, $this->end_at, $this->getKey(), DriveMode::SelfDrive, $driver->id)]);
        }
    }

    public function returnDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_driver_id');
    }

    /**
     * Captain and crew checks on every save of a yacht charter: people from the
     * driver module who can do that work, active, and not busy elsewhere.
     */
    public function guardCrew(): void
    {
        if ($this->service_type !== ServiceType::Yacht) {
            return;
        }
        if ($this->exists && ! $this->isDirty(['captain_id', 'crew_ids', 'start_at', 'end_at', 'status'])) {
            return;
        }
        $people = array_filter(array_merge([$this->captain_id], (array) $this->crew_ids));
        if (! $people || ! Availability::holds($this->status)) {
            return;
        }
        foreach ($people as $i => $id) {
            $person = User::with('driverProfile')->find($id);
            $field = $i === 0 && $this->captain_id ? 'data.captain_id' : 'data.crew_ids';
            $need = $field === 'data.captain_id' ? 'captain' : 'crew';
            if (! $person || ! $person->hasRole('Driver') || ! $person->is_active) {
                throw ValidationException::withMessages([$field => 'Pick active people from the drivers & crew list.']);
            }
            if (! ($person->driverProfile?->does($need) || ($need === 'crew' && $person->driverProfile?->does('captain')))) {
                throw ValidationException::withMessages([$field => $person->name.' is not set up as '.($need === 'captain' ? 'a captain' : 'crew').' (Drivers & crew › profile).']);
            }
            if ($off = Availability::offTime($person->id, $this->start_at, $this->end_at, DriveMode::WithDriver)) {
                throw ValidationException::withMessages([$field => $person->name.' is not available then ('.$off->label().').']);
            }
            if ($clash = Availability::personConflict($person->id, $this->start_at, $this->end_at, $this->getKey(), DriveMode::WithDriver)) {
                throw ValidationException::withMessages([$field => $person->name.' is already busy on '.$clash->reference.' ('.$clash->start_at->format('d M H:i').' – '.$clash->end_at->format('d M H:i').').'
                    .Availability::freeText($need, $this->start_at, $this->end_at, $this->getKey(), DriveMode::WithDriver, $person->id)]);
            }
        }
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function crewMembers(): \Illuminate\Support\Collection
    {
        return $this->crew_ids ? User::whereIn('id', $this->crew_ids)->get() : collect();
    }

    /** Documents and handover / return photos are private (staff-only signed links). */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('delivery')->useDisk('private');
        $this->addMediaCollection('return')->useDisk('private');
        $this->addMediaCollection('documents')->useDisk('private');
        $this->addMediaCollection('signatures')->useDisk('private'); // 4 Nov (15): customer signs on the driver's phone
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(480)->height(320)->nonQueued();
    }

    /* ---------- Relations ---------- */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function bookable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function tripDate(): BelongsTo
    {
        return $this->belongsTo(TripDate::class);
    }

    public function packageDeparture(): BelongsTo
    {
        return $this->belongsTo(PackageDeparture::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** Delivery and collection for the driver (kept in step by DriverJobs). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Supplier::class)->withTrashed();
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'supplier_bill_id')->withTrashed();
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(BookingJob::class);
    }

    public function damageReports(): HasMany
    {
        return $this->hasMany(DamageReport::class);
    }

    /** 18 Nov: the rental contract made from this booking. */
    public function contract(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RentalContract::class);
    }

    /** Km included per day and the extra km rate: this booking's, else the car's. */
    public function kmLimitPerDay(): ?int
    {
        return $this->km_limit_per_day ?: (($this->bookable instanceof Car && $this->bookable->daily_km_limit) ? (int) $this->bookable->daily_km_limit : null);
    }

    public function extraKmRate(): ?float
    {
        return $this->extra_km_rate !== null ? (float) $this->extra_km_rate : (($this->bookable instanceof Car && $this->bookable->extra_km_charge) ? (float) $this->bookable->extra_km_charge : null);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** Which document checklist applies: self_drive, with_driver, yacht, trip (+ corporate). */
    public function documentKinds(): array
    {
        return self::documentKindsFor($this->service_type, $this->drive_mode, $this->customer, $this->crew_mode);
    }

    /** Shared with quotations: the checklist for a service, rental type and customer. */
    public static function documentKindsFor(?ServiceType $service, ?DriveMode $mode, ?Customer $customer, ?string $crewMode = null): array
    {
        $kinds = [match ($service) {
            ServiceType::Yacht => 'yacht',
            ServiceType::Trip => 'trip',
            ServiceType::Package => 'package',
            ServiceType::Transfer => 'transfer',
            default => $mode === DriveMode::WithDriver ? 'with_driver' : 'self_drive',
        }];
        if ($service === ServiceType::Yacht && $crewMode === 'bareboat') {
            $kinds[] = 'bareboat';
        }
        if ($customer && $customer->type !== \App\Enums\CustomerType::Individual) {
            $kinds[] = 'corporate';
        }
        // 18 Nov: documents per resident / GCC / tourist for car rentals (Business settings › Documents).
        if ($service === ServiceType::Car && $customer && in_array($customer->residency, ['resident', 'gcc', 'tourist'], true)) {
            $kinds[] = $customer->residency;
        }

        return $kinds;
    }

    /** Document types required for this booking (from Settings). */
    public function requiredDocumentTypes(): array
    {
        return self::requiredTypesFor($this->documentKinds());
    }

    public static function requiredTypesFor(array $kinds): array
    {
        $rules = (array) Setting::read('doc_requirements', []);

        return collect($kinds)
            ->flatMap(fn ($kind) => (array) ($rules[$kind] ?? []))
            ->unique()->values()->all();
    }

    /** Checklist: type => [label, status text, ok]. */
    public function documentChecklist(): array
    {
        return self::checklistFor($this->customer_id, $this->requiredDocumentTypes(), $this->start_at);
    }

    public static function checklistFor(?int $customerId, array $types, $on = null): array
    {
        $docs = $customerId
            ? CustomerDocument::where('customer_id', $customerId)->latest()->get()->groupBy('type')
            : collect();
        $on ??= now();

        return collect($types)->mapWithKeys(function ($type) use ($docs, $on) {
            $label = \App\Support\Options::documentLabel($type);
            $list = $docs->get($type, collect());
            $valid = $list->first(fn (CustomerDocument $d) => $d->isValidOn($on));
            $latest = $list->first();

            $text = match (true) {
                (bool) $valid => 'Approved',
                ! $latest => 'Missing',
                $latest->status === \App\Enums\DocumentStatus::Approved => 'Expired',
                default => $latest->status->getLabel(),
            };

            return [$type => [$label, $text, (bool) $valid]];
        })->all();
    }

    /** Required documents still missing, but only when Settings says to block the handover. */
    public function missingDocuments(): array
    {
        if (! Setting::read('doc_block_handover')) {
            return [];
        }

        return collect($this->documentChecklist())->reject(fn ($row) => $row[2])->map(fn ($row) => $row[0].' ('.strtolower($row[1]).')')->values()->all();
    }

    /* ---------- Scopes ---------- */

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', BookingStatus::blocking())->notExpired();
    }

    /** Leaves out website bookings whose time to pay has run out (they no longer hold anything). */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('hold_until')->orWhere('hold_until', '>', now()));
    }

    /** An unpaid website booking whose time to pay has run out. */
    public function holdExpired(): bool
    {
        return $this->hold_until !== null && $this->status === BookingStatus::PendingPayment && $this->hold_until->isPast();
    }

    /* ---------- Helpers ---------- */

    public function title(): string
    {
        return trim(($this->reference ? $this->reference.' · ' : '').($this->bookable?->name ?? $this->service_type?->getLabel()));
    }

    public function isCar(): bool
    {
        return $this->service_type === ServiceType::Car;
    }

    /** Word used on buttons for non-car bookings: "trip" (yacht trips, packages) or "charter" (old yacht charters). */
    public function serviceNoun(): string
    {
        return $this->service_type === ServiceType::Yacht ? 'charter' : 'trip';
    }

    public function amountDue(): float
    {
        // 4 Nov (C.1): plus charge lines added later (fines, tolls, fees).
        return round((float) $this->total + (float) $this->extra_charges + (float) $this->charge_lines_total, 2);
    }

    /** 4 Nov (C.1): fines, tolls and fees added after the booking was priced. */
    public function chargeLines(): HasMany
    {
        return $this->hasMany(BookingCharge::class);
    }

    public function fines(): HasMany
    {
        return $this->hasMany(TrafficFine::class);
    }

    public function tollTrips(): HasMany
    {
        return $this->hasMany(TollTrip::class);
    }

    public function depositSettlements(): HasMany
    {
        return $this->hasMany(DepositSettlement::class);
    }

    public function preauths(): HasMany
    {
        return $this->hasMany(DepositPreauth::class);
    }

    /** Security deposit still held (received minus refunded). */
    public function depositHeld(): float
    {
        $in = (float) $this->transactions()->where('type', TransactionType::Income)->where('category', 'deposit')->where('status', PaymentStatus::Paid)->whereNull('reversed_at')->sum('amount');
        $out = (float) $this->transactions()->where('type', TransactionType::Expense)->where('category', 'deposit_refund')->where('status', PaymentStatus::Paid)->whereNull('reversed_at')->sum('amount');

        return round($in - $out, 2);
    }

    public function revenueCategory(): string
    {
        return match ($this->service_type) {
            ServiceType::Yacht => 'yacht_charter',
            ServiceType::Trip => 'yacht_trip',
            ServiceType::Package => 'travel_package',
            ServiceType::Transfer => 'transfer',
            default => $this->drive_mode === DriveMode::WithDriver ? 'chauffeur' : 'car_rental',
        };
    }

    /** Record money received for this booking. */
    public function recordPayment(float $amount, ?string $method, ?string $category = null, ?string $description = null, ?string $reference = null): Transaction
    {
        $category ??= $this->revenueCategory();

        // A double click or a retried request must not record the same money twice.
        $duplicate = $this->transactions()
            ->where('type', TransactionType::Income)
            ->where('is_balance', false)
            ->where('category', $category)
            ->where('amount', round($amount, 2))
            ->where('method', $method)
            ->where('user_id', auth()->id())
            ->where('created_at', '>=', now()->subSeconds(30))
            ->first();
        if ($duplicate) {
            return $duplicate;
        }

        return $this->transactions()->create([
            'type' => TransactionType::Income,
            'category' => $category,
            'date' => today(),
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'method' => $method,
            'reference' => $reference,
            'description' => $description ?? 'Payment · '.$this->reference,
        ]);
    }

    /**
     * Keeps payment status, paid amount and balance up to date, and keeps one
     * system "balance due" row in Accounts so the money to collect shows up in
     * "To collect". Marking that row paid turns it into a normal payment.
     */
    public function syncBalance(): void
    {
        if (static::$syncing || ! $this->exists) {
            return;
        }

        static::$syncing = true;

        try {
            $row = $this->transactions()->where('is_balance', true)->first();

            // Part of the balance was received: split it off as its own payment.
            if ($row && $row->status === PaymentStatus::PartiallyPaid && (float) $row->amount_paid > 0) {
                $this->transactions()->create([
                    'type' => TransactionType::Income,
                    'category' => $row->category,
                    'date' => today(),
                    'amount' => $row->amount_paid,
                    'status' => PaymentStatus::Paid,
                    'method' => $row->method,
                    'reference' => $row->reference,
                    'description' => 'Payment · '.$this->reference,
                ]);
                $row->forceFill(['amount_paid' => null, 'status' => PaymentStatus::Pending])->saveQuietly();
            }

            $paid = Transaction::totals($this->transactions()->where('is_balance', false)->getQuery())['received'];
            // 4 Nov (C.1): charge lines are part of what is owed; pass-through ones are not revenue.
            $lines = BookingCharge::query()->where('booking_id', $this->id)->whereNull('voided_at');
            $this->charge_lines_total = round((float) (clone $lines)->sum('amount'), 2);
            $this->pass_through_total = round((float) (clone $lines)->where('nature', 'pass_through')->sum('amount'), 2);
            $billable = in_array($this->status, BookingStatus::billable(), true);
            $due = $billable ? $this->amountDue() : 0;
            $balance = max(0, round($due - $paid, 2));
            $dueDate = \App\Support\Pricing\Advance::balanceDue($this); // Business settings (4 Nov); default as before

            // Advance (4 Nov, 11): a % of the price (never the deposit); paid money excludes deposits.
            $advance = $this->advance_percent !== null ? \App\Support\Pricing\Advance::amount((float) $this->total, (float) $this->advance_percent) : null;
            $reached = $advance !== null && $paid > 0 && $paid + 0.005 >= $advance;

            if ($balance > 0) {
                $row ??= new Transaction(['booking_id' => $this->id, 'is_balance' => true]);
                $row->forceFill([
                    'type' => TransactionType::Income,
                    'category' => $this->revenueCategory(),
                    'date' => $this->start_at?->toDateString() ?? today(),
                    'amount' => $balance,
                    'status' => $dueDate && $dueDate->isPast() ? PaymentStatus::Overdue : PaymentStatus::Pending,
                    'due_date' => $dueDate,
                    'customer_id' => $this->customer_id,
                    'related_type' => $this->bookable_type,
                    'related_id' => $this->bookable_id,
                    'description' => 'Balance due · '.$this->reference,
                ])->saveQuietly();
            } elseif ($row) {
                $row->forceDelete();
            }

            $this->forceFill([
                'hold_until' => $paid > 0 ? null : $this->hold_until, // first payment: the website hold ends
                'charge_lines_total' => $this->charge_lines_total,
                'pass_through_total' => $this->pass_through_total,
                'advance_required' => $advance,
                'advance_reached_at' => $reached ? ($this->advance_reached_at ?? now()) : null,
                'paid_amount' => $paid,
                'balance' => $balance,
                'payment_status' => match (true) {
                    $due <= 0 => null,
                    $balance <= 0 => PaymentStatus::Paid,
                    $paid > 0 => PaymentStatus::PartiallyPaid,
                    $dueDate && $dueDate->isPast() => PaymentStatus::Overdue,
                    default => PaymentStatus::Pending,
                },
            ])->saveQuietly();
        } finally {
            static::$syncing = false;
        }

        rescue(fn () => \App\Support\Fines\Fines::markCollected($this), report: false); // 4 Nov (2.4)

        if ($this->wasChanged('advance_reached_at') && $this->advance_reached_at) {
            rescue(fn () => \App\Support\Notifications\EventHooks::advanceReached($this));
        }
        $this->confirmOnAdvance();
    }

    /**
     * 4 Nov (11): with "Confirm when the advance is paid" on, a Pending payment booking becomes
     * Confirmed once the advance is reached. If it can't be confirmed (dates taken, price waiting),
     * it stays Pending payment and the team is told.
     */
    protected function confirmOnAdvance(): void
    {
        if ($this->status !== BookingStatus::PendingPayment || ! $this->advance_reached_at || $this->advance_required === null
            || (float) $this->advance_required <= 0 || $this->priceBlocked() || ! \App\Support\Pricing\Advance::confirmsBooking()) {
            return;
        }

        // Same rule as "Create booking": with "documents approved before confirming" on, missing documents keep it pending.
        if (Setting::read('doc_block_confirm') && ($missing = collect($this->documentChecklist())->reject(fn ($row) => $row[2])->map(fn ($row) => $row[0])->values()->all())) {
            if ($this->wasChanged('advance_reached_at')) { // told once, when the advance is reached
                $url = \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $this]);
                $body = 'Confirm it once these documents are approved: '.implode(', ', $missing).'.';
                DB::afterCommit(fn () => rescue(fn () => \App\Support\Drivers\Notify::staff('manage-bookings', $this->reference.': advance paid, documents missing', $body, $url, $this->assignee, 'warning', event: 'advance_reached'), report: false));
            }

            return;
        }

        try {
            $this->status = BookingStatus::Confirmed;
            $this->save();
            AuditLog::note($this, 'status', 'Confirmed automatically: the advance of AED '.number_format((float) $this->advance_required, 2).' is paid.');

            return; // the team and the customer hear about it as "Booking confirmed" and "Advance paid"
        } catch (ValidationException $e) {
            $this->status = BookingStatus::PendingPayment;
            $this->syncOriginalAttribute('status');
            $title = $this->reference.': advance paid, but it could not be confirmed';
            $body = collect($e->errors())->flatten()->first();
            $color = 'danger';
        }
        $url = \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $this]);
        DB::afterCommit(fn () => rescue(fn () => \App\Support\Drivers\Notify::staff('manage-bookings', $title, $body, $url, $this->assignee, $color, event: 'advance_reached'), report: false));
    }

    /** Moves the linked lead along its pipeline as the booking progresses. */
    public function advanceLead(): void
    {
        $lead = $this->lead;
        if (! $lead || ! $lead->pipeline) {
            return;
        }

        $lead->advanceTo($lead->pipeline->stageForBooking($this->status, $this->payment_status === PaymentStatus::Paid));
    }

    /**
     * Price suggestion: [rate_type, quantity, rate, amount] for the rental only.
     * Kept for older callers; the full calculation is App\Support\Pricing\PriceEngine.
     */
    public static function quote(?Model $item, $start, $end, ?string $driveMode = null, ?int $guests = null, ?PackageDeparture $departure = null): ?array
    {
        $service = match (true) {
            $item instanceof Car => 'car',
            $item instanceof Yacht => 'yacht',
            $item instanceof Trip => 'trip',
            $item instanceof Package => 'package',
            default => null,
        };
        $result = \App\Support\Pricing\PriceEngine::calculate([
            'service_type' => $service, 'item' => $item, 'start_at' => $start, 'end_at' => $end,
            'drive_mode' => $driveMode, 'guests' => $guests, 'departure' => $departure,
        ]);

        return $result ? [$result['rate_type'], $result['quantity'], $result['rate'], $result['base_amount']] : null;
    }

    public static function rateLabel(?string $type): string
    {
        return [
            'hour' => 'hour', 'day' => 'day', 'week' => 'week', 'month' => 'month',
            'half_day' => 'half day', 'full_day' => 'full day', 'guest' => 'guest', 'trip' => 'trip', 'package' => 'package',
        ][$type] ?? (string) $type;
    }

    /** Car / yacht status after bookings change (used by Car and Yacht). */
    public static function fleetStatusFor(Model $item): CarStatus|YachtStatus|null
    {
        $bookings = static::query()->where('bookable_type', $item->getMorphClass())->where('bookable_id', $item->getKey());

        if ($item instanceof Car) {
            if ((clone $bookings)->where('status', BookingStatus::InProgress)->exists()) {
                return CarStatus::Rented;
            }

            return (clone $bookings)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->where('end_at', '>=', now())->exists()
                ? CarStatus::Reserved
                : CarStatus::Available;
        }

        if ($item instanceof Yacht) {
            return (clone $bookings)->blocking()->where('end_at', '>=', now())->exists()
                ? YachtStatus::Booked
                : YachtStatus::Available;
        }

        return null;
    }

    public function transferService(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\TransferService::class)->withTrashed();
    }

    public function vehicleCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\VehicleCategory::class)->withTrashed();
    }
}
