<?php

namespace App\Models;

use App\Enums\YachtStatus;
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

class Yacht extends Model implements HasMedia
{
    use Audited, HasExpiringDocuments, InteractsWithMedia, PublishesToWebsite, SoftDeletes;

    public const CATEGORY = Category::YACHTS;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => YachtStatus::class,
            'readiness' => Readiness::class,
            'amenities' => 'array',
            'specifications' => 'array',
            'additional_charges' => 'array',
            'hourly_price' => 'decimal:2',
            'half_day_price' => 'decimal:2',
            'full_day_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'sunset_price' => 'decimal:2',
            'partner_commission' => 'decimal:2',
            'addons' => 'array',
            'fuel_included' => 'boolean',
            'license_expiry' => 'date',
            'insurance_expiry' => 'date',
            'next_service_date' => 'date',
            'captain_included' => 'boolean',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public static function expiringDocuments(): array
    {
        return [
            'Licence' => 'license_expiry',
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

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'related');
    }

    public function isReady(): bool
    {
        return ($this->readiness ?? Readiness::Ready) === Readiness::Ready;
    }

    public function bookings(): MorphMany
    {
        return $this->morphMany(Booking::class, 'bookable');
    }

    /** Current or next booking that holds this yacht. */
    public function nextBooking(): ?Booking
    {
        return $this->bookings()->blocking()->where('end_at', '>=', now())->orderBy('start_at')->first();
    }

    /** Booked / on rent / free, from its bookings. Maintenance and Unavailable are left alone. */
    public function refreshBookingStatus(): void
    {
        if (in_array($this->status, [YachtStatus::Maintenance, YachtStatus::Unavailable], true)) {
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
