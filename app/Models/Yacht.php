<?php

namespace App\Models;

use App\Enums\YachtStatus;
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
    use InteractsWithMedia, PublishesToWebsite, SoftDeletes;

    public const CATEGORY = Category::YACHTS;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => YachtStatus::class,
            'amenities' => 'array',
            'specifications' => 'array',
            'additional_charges' => 'array',
            'hourly_price' => 'decimal:2',
            'half_day_price' => 'decimal:2',
            'full_day_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'captain_included' => 'boolean',
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

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'interested_in');
    }
}
