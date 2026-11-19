<?php

namespace App\Models;

use App\Enums\CarStatus;
use App\Enums\Readiness;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasExpiringDocuments;
use App\Models\Concerns\PublishesToWebsite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Car extends Model implements HasMedia
{
    use Audited, InteractsWithMedia, PublishesToWebsite, SoftDeletes, \App\Models\Concerns\WarnsAboutBookings;
    use HasExpiringDocuments { documentAlerts as traitDocumentAlerts; }

    public const CATEGORY = Category::CARS;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CarStatus::class,
            'readiness' => Readiness::class,
            'features' => 'array',
            'specifications' => 'array',
            'daily_price' => 'decimal:2',
            'weekly_price' => 'decimal:2',
            'monthly_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'hourly_price' => 'decimal:2',
            'chauffeur_daily_price' => 'decimal:2',
            'chauffeur_hourly_price' => 'decimal:2',
            'self_drive' => 'boolean',
            'with_driver' => 'boolean',
            'extra_km_charge' => 'decimal:2',
            'salik_charge' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'partner_commission' => 'decimal:2',
            'addons' => 'array',
            'delivery_available' => 'boolean',
            'registration_expiry' => 'date',
            'insurance_expiry' => 'date',
            'next_service_date' => 'date',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'tank_litres' => 'decimal:1',      // 5 Nov (S5)
            'fuel_level_8' => 'integer',
            'fuel_level_at' => 'datetime',
        ];
    }

    public static function expiringDocuments(): array
    {
        return [
            'Registration' => 'registration_expiry',
            'Insurance' => 'insurance_expiry',
            'Service' => 'next_service_date',
        ];
    }

    /** 19 Nov: also the service by km (next service km vs the odometer), warned 1,000 km before. */
    public function documentAlerts(): array
    {
        $alerts = $this->traitDocumentAlerts();
        if ($this->next_service_km && $this->mileage !== null) {
            $left = (int) $this->next_service_km - (int) $this->mileage;
            if ($left <= 0) {
                $alerts['Service km'] = 'Service overdue by '.number_format(abs($left)).' km';
            } elseif ($left <= 1000) {
                $alerts['Service km'] = 'Service due in '.number_format($left).' km';
            }
        }

        return $alerts;
    }

    /** Registration or insurance already expired: the car must not go out (19 Nov). */
    public function expiredPapers(?\Illuminate\Support\Carbon $on = null): array
    {
        $on ??= today();

        return collect(['Registration' => $this->registration_expiry, 'Insurance' => $this->insurance_expiry])
            ->filter(fn ($d) => $d && $d->lt($on))->map(fn ($d, $label) => $label.' expired '.$d->format('d M Y'))->values()->all();
    }

    /** 4 Nov (Phase 4): traffic fines and toll trips of this car. */
    public function fines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TrafficFine::class);
    }

    public function tollTrips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TollTrip::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(480)->height(320)->nonQueued();
        $this->addMediaConversion('large')->width(1600)->nonQueued();
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(CarMaintenance::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'related');
    }

    /** Drivers who normally drive this car (default assignment, not a booking). */
    public function drivers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'car_driver')->withTimestamps();
    }

    public function isReady(): bool
    {
        return ($this->readiness ?? Readiness::Ready) === Readiness::Ready;
    }

    public function bookings(): MorphMany
    {
        return $this->morphMany(Booking::class, 'bookable');
    }

    /** Current or next booking that holds this car. */
    public function nextBooking(): ?Booking
    {
        return $this->bookings()->blocking()->where('end_at', '>=', now())->orderBy('start_at')->first();
    }

    /** Booked / on rent / free, from its bookings. Maintenance and Unavailable are left alone. */
    public function refreshBookingStatus(): void
    {
        if (in_array($this->status, [CarStatus::Maintenance, CarStatus::Unavailable], true)) {
            return;
        }

        $status = Booking::fleetStatusFor($this);
        if ($status && $status !== $this->status) {
            $this->forceFill(['status' => $status])->saveQuietly();
        }
    }

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'interested_in');
    }

    public function vehicleCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Supplier::class)->withTrashed();
    }

    /** 5 Nov (S5): fill-ups of this car. */
    public function fuelEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FuelEntry::class);
    }
}
