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
use Illuminate\Validation\ValidationException;
use App\Models\Concerns\Audited;
use App\Support\Availability;
use App\Support\Rules;
use App\Support\Tax;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Booking extends Model implements HasMedia
{
    use Audited, InteractsWithMedia, SoftDeletes;

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

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'service_type' => ServiceType::class,
            'drive_mode' => DriveMode::class,
            'payment_status' => PaymentStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'charges' => 'array',
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'extras_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'security_deposit' => 'decimal:2',
            'extra_charges' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Booking $booking) {
            $booking->service_type ??= match ($booking->bookable_type) {
                'yacht' => ServiceType::Yacht,
                'trip' => ServiceType::Trip,
                'package' => ServiceType::Package,
                default => ServiceType::Car,
            };
            if ($booking->bookable_id) {
                $booking->bookable_type = $booking->service_type->value; // morph aliases match: car / yacht / trip
            }
            if ($booking->service_type !== ServiceType::Car) {
                $booking->drive_mode = null;
            }
            // Any car booking can have a driver: with driver he drives, self-drive he delivers and collects.
            if ($booking->service_type !== ServiceType::Car) {
                $booking->driver_id = null;
            }
            [$booking->tax_amount, $booking->total] = Tax::apply(
                (float) $booking->base_amount + (float) $booking->extras_amount - (float) $booking->discount,
                $booking->tax_rate !== null ? (float) $booking->tax_rate : null,
                (bool) $booking->tax_inclusive,
            );
            if ($booking->service_type !== ServiceType::Package) {
                $booking->package_departure_id = null;
            }
            $booking->assigned_to ??= auth()->id();

            $booking->guardAvailability();
        });

        static::created(function (Booking $booking) {
            $booking->forceFill(['reference' => sprintf('BK-%05d', $booking->id)])->saveQuietly();
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

        $relevant = ! $this->exists || $this->isDirty(['status', 'start_at', 'end_at', 'bookable_type', 'bookable_id', 'trip_date_id', 'package_departure_id', 'guests']);
        if (! $relevant) {
            return;
        }

        $type = $this->service_type?->value;

        if (Availability::holds($this->status) && in_array($type, ['car', 'yacht'], true) && $this->bookable_id) {
            if ($message = Availability::assetConflict($type, (int) $this->bookable_id, $this->start_at, $this->end_at, $this->getKey())) {
                throw ValidationException::withMessages(['data.end_at' => $message]);
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
        if (! $this->driver_id || $this->service_type !== ServiceType::Car) {
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
            if (Setting::read('driver_block_incomplete') && ($missing = \App\Support\Drivers\DriverPapers::problems($driver))) {
                throw ValidationException::withMessages(['data.driver_id' => $driver->name.' cannot get jobs until these are sorted: '.implode(', ', $missing).'.']);
            }
        }

        $newlyHolding = ! $this->exists
            || $this->isDirty(['driver_id', 'start_at', 'end_at', 'drive_mode'])
            || ! Availability::holds($this->getOriginal('status') instanceof BookingStatus ? $this->getOriginal('status') : BookingStatus::tryFrom((string) $this->getOriginal('status')));
        if ($driver && $newlyHolding && Availability::holds($this->status)) {
            if ($clash = Availability::driverConflict($driver->id, $this->start_at, $this->end_at, $this->getKey(), $this->drive_mode)) {
                throw ValidationException::withMessages(['data.driver_id' => $driver->name.' is already busy on '.$clash->reference.' ('.$clash->start_at->format('d M H:i').' – '.$clash->end_at->format('d M H:i').').']);
            }
        }
    }

    /** Documents and handover / return photos are private (staff-only signed links). */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('delivery')->useDisk('private');
        $this->addMediaCollection('return')->useDisk('private');
        $this->addMediaCollection('documents')->useDisk('private');
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
    public function jobs(): HasMany
    {
        return $this->hasMany(BookingJob::class);
    }

    public function damageReports(): HasMany
    {
        return $this->hasMany(DamageReport::class);
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
        $kinds = [match ($this->service_type) {
            ServiceType::Yacht => 'yacht',
            ServiceType::Trip => 'trip',
            ServiceType::Package => 'package',
            default => $this->drive_mode === DriveMode::WithDriver ? 'with_driver' : 'self_drive',
        }];
        if ($this->customer && $this->customer->type !== \App\Enums\CustomerType::Individual) {
            $kinds[] = 'corporate';
        }

        return $kinds;
    }

    /** Document types required for this booking (from Settings). */
    public function requiredDocumentTypes(): array
    {
        $rules = (array) Setting::read('doc_requirements', []);

        return collect($this->documentKinds())
            ->flatMap(fn ($kind) => (array) ($rules[$kind] ?? []))
            ->unique()->values()->all();
    }

    /** Checklist: type => [label, status text, ok]. */
    public function documentChecklist(): array
    {
        $docs = $this->customer_id
            ? CustomerDocument::where('customer_id', $this->customer_id)->latest()->get()->groupBy('type')
            : collect();
        $on = $this->start_at ?? now();

        return collect($this->requiredDocumentTypes())->mapWithKeys(function ($type) use ($docs, $on) {
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
        return $query->whereIn('status', BookingStatus::blocking());
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
        return round((float) $this->total + (float) $this->extra_charges, 2);
    }

    /** Security deposit still held (received minus refunded). */
    public function depositHeld(): float
    {
        $in = (float) $this->transactions()->where('type', TransactionType::Income)->where('category', 'deposit')->where('status', PaymentStatus::Paid)->sum('amount');
        $out = (float) $this->transactions()->where('type', TransactionType::Expense)->where('category', 'deposit_refund')->where('status', PaymentStatus::Paid)->sum('amount');

        return round($in - $out, 2);
    }

    public function revenueCategory(): string
    {
        return match ($this->service_type) {
            ServiceType::Yacht => 'yacht_charter',
            ServiceType::Trip => 'yacht_trip',
            ServiceType::Package => 'travel_package',
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
            $billable = in_array($this->status, BookingStatus::billable(), true);
            $due = $billable ? $this->amountDue() : 0;
            $balance = max(0, round($due - $paid, 2));
            $dueDate = ($this->isCar() ? $this->end_at : $this->start_at)?->copy()->startOfDay();

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

    /** Price suggestion from the car / yacht / trip / package rates. Returns [rate_type, quantity, rate, amount]. */
    public static function quote(?Model $item, $start, $end, ?string $driveMode = null, ?int $guests = null, ?PackageDeparture $departure = null): ?array
    {
        if ($item instanceof Package) {
            return $item->priceFor($guests, $departure);
        }

        if (! $item || ! $start) {
            return null;
        }

        $start = Carbon::parse($start);
        $end = $end ? Carbon::parse($end) : $start->copy();
        $hours = max(1, (int) ceil($start->diffInMinutes($end) / 60));

        if ($item instanceof Trip) {
            if ($item->price_unit === 'per_trip') {
                return ['trip', 1, (float) $item->price, (float) $item->price];
            }
            $guests = max(1, (int) $guests);

            return ['guest', $guests, (float) $item->price, round($guests * (float) $item->price, 2)];
        }

        if ($item instanceof Yacht) {
            if ($hours >= Rules::pricing('yacht_full_day_from_hours') && $item->full_day_price) {
                $days = max(1, (int) ceil($hours / 24));

                return ['full_day', $days, (float) $item->full_day_price, round($days * (float) $item->full_day_price, 2)];
            }
            if ($hours === Rules::pricing('yacht_half_day_hours') && $item->half_day_price) {
                return ['half_day', 1, (float) $item->half_day_price, (float) $item->half_day_price];
            }

            return ['hour', $hours, (float) $item->hourly_price, round($hours * (float) $item->hourly_price, 2)];
        }

        if ($item instanceof Car) {
            $days = max(1, (int) ceil($hours / 24));

            if ($driveMode === DriveMode::WithDriver->value) {
                if ($hours < 24 && $item->chauffeur_hourly_price && (! $item->driver_hours_per_day || $hours < $item->driver_hours_per_day)) {
                    return ['hour', $hours, (float) $item->chauffeur_hourly_price, round($hours * (float) $item->chauffeur_hourly_price, 2)];
                }

                return ['day', $days, (float) $item->chauffeur_daily_price, round($days * (float) $item->chauffeur_daily_price, 2)];
            }

            if ($hours < 24 && $item->hourly_price && $hours < Rules::pricing('hourly_max_hours')) {
                return ['hour', $hours, (float) $item->hourly_price, round($hours * (float) $item->hourly_price, 2)];
            }
            if ($days >= Rules::pricing('monthly_from_days') && $item->monthly_price) {
                $months = round($days / 30, 2);

                return ['month', $months, (float) $item->monthly_price, round($months * (float) $item->monthly_price, 2)];
            }
            if ($days >= Rules::pricing('weekly_from_days') && $item->weekly_price) {
                $weeks = round($days / 7, 2);

                return ['week', $weeks, (float) $item->weekly_price, round($weeks * (float) $item->weekly_price, 2)];
            }

            return ['day', $days, (float) $item->daily_price, round($days * (float) $item->daily_price, 2)];
        }

        return null;
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
}
