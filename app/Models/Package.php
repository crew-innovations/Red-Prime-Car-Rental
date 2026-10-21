<?php

namespace App\Models;

use App\Enums\TripStatus;
use App\Models\Concerns\Audited;
use App\Models\Concerns\PublishesToWebsite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A trip or travel package sold to customers: one-day trips, multi-day tours,
 * safaris, full packages. Content, itinerary, prices and departures are all
 * managed here and published to the website through the API.
 */
class Package extends Model implements HasMedia
{
    use Audited, InteractsWithMedia, PublishesToWebsite, SoftDeletes;

    public const CATEGORY = 'packages'; // website section switch (Website › Categories)

    public const BOOKING_MODES = [
        'departures' => 'Fixed departure dates',
        'on_request' => 'Customer picks a date (we confirm)',
    ];

    public const PRICING_MODES = [
        'per_person' => 'Per person',
        'fixed' => 'Fixed price for the package',
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'booking_mode' => 'on_request',
        'pricing_mode' => 'per_person',
        'currency' => 'AED',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'destinations' => 'array',
            'price_tiers' => 'array',
            'itinerary' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'activities' => 'array',
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'child_price' => 'decimal:2',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('gallery');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(480)->height(320)->nonQueued();
        $this->addMediaConversion('large')->width(1600)->nonQueued();
    }

    /* ---------- Relations ---------- */

    public function category(): BelongsTo
    {
        return $this->belongsTo(PackageCategory::class, 'package_category_id');
    }

    public function departures(): HasMany
    {
        return $this->hasMany(PackageDeparture::class)->orderBy('start_date');
    }

    public function upcomingDepartures(): HasMany
    {
        return $this->hasMany(PackageDeparture::class)
            ->where('start_date', '>=', today())
            ->where('is_available', true)
            ->orderBy('start_date');
    }

    public function bookings(): MorphMany
    {
        return $this->morphMany(Booking::class, 'bookable');
    }

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'interested_in');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'related');
    }

    /** Packages have no fleet status; kept so bookings can call it like cars. */
    public function refreshBookingStatus(): void {}

    /* ---------- Helpers ---------- */

    public function isPerPerson(): bool
    {
        return $this->pricing_mode !== 'fixed';
    }

    public function usesDepartures(): bool
    {
        return $this->booking_mode === 'departures';
    }

    /** "3 days / 2 nights", "1 day" or null. */
    public function durationLabel(): ?string
    {
        $parts = [];
        if ($this->duration_days) {
            $parts[] = $this->duration_days.' day'.($this->duration_days > 1 ? 's' : '');
        }
        if ($this->duration_nights) {
            $parts[] = $this->duration_nights.' night'.($this->duration_nights > 1 ? 's' : '');
        }

        return $parts ? implode(' / ', $parts) : null;
    }

    /** Price a customer pays per person (or for the package), before guests are counted. */
    public function unitPrice(?PackageDeparture $departure = null, ?int $guests = null): ?float
    {
        $price = $departure?->price !== null ? (float) $departure->price : ($this->price !== null ? (float) $this->price : null);

        // A sale price only lowers the standard price; a departure price always wins.
        if ($departure?->price === null && $this->sale_price !== null && ($price === null || (float) $this->sale_price < $price)) {
            $price = (float) $this->sale_price;
        }

        // Group tiers: the lowest per-person price whose minimum the group reaches.
        if ($this->isPerPerson() && $guests && $departure?->price === null) {
            foreach ((array) $this->price_tiers as $tier) {
                $min = (int) ($tier['min_guests'] ?? 0);
                $tierPrice = isset($tier['price']) && $tier['price'] !== '' ? (float) $tier['price'] : null;
                if ($min > 0 && $tierPrice !== null && $guests >= $min && ($price === null || $tierPrice < $price)) {
                    $price = $tierPrice;
                }
            }
        }

        return $price;
    }

    /**
     * Server-side price for a number of guests (same shape as Booking::quote):
     * [rate_type, quantity, rate, amount] or null when no price is set.
     */
    public function priceFor(?int $guests, ?PackageDeparture $departure = null): ?array
    {
        $guests = max(1, (int) $guests);
        $unit = $this->unitPrice($departure, $guests);
        if ($unit === null) {
            return null;
        }

        if (! $this->isPerPerson()) {
            return ['package', 1, $unit, round($unit, 2)];
        }

        return ['guest', $guests, $unit, round($guests * $unit, 2)];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TripStatus::Active);
    }
}
