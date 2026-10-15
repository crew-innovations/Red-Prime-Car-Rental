<?php

namespace App\Models;

use App\Enums\CarStatus;
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
    use InteractsWithMedia, PublishesToWebsite, SoftDeletes;

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
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
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
        return $this->hasMany(CarMaintenance::class)->latest('date');
    }

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'interested_in');
    }
}
