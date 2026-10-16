<?php

namespace App\Models;

use App\Enums\CarStatus;
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
    use HasExpiringDocuments, InteractsWithMedia, PublishesToWebsite, SoftDeletes;

    public const CATEGORY = Category::CARS;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CarStatus::class,
            'features' => 'array',
            'specifications' => 'array',
            'daily_price' => 'decimal:2',
            'weekly_price' => 'decimal:2',
            'monthly_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'hourly_price' => 'decimal:2',
            'chauffeur_daily_price' => 'decimal:2',
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

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'interested_in');
    }
}
