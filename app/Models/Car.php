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
    use Audited, HasExpiringDocuments, InteractsWithMedia, PublishesToWebsite, SoftDeletes, \App\Models\Concerns\WarnsAboutBookings;

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
}
