<?php

namespace App\Models;

use App\Enums\TripStatus;
use App\Models\Concerns\PublishesToWebsite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Trip extends Model implements HasMedia
{
    use InteractsWithMedia, PublishesToWebsite, SoftDeletes;

    public const CATEGORY = Category::YACHT_TRIPS;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'additional_charges' => 'array',
            'included_services' => 'array',
            'excluded_services' => 'array',
            'price' => 'decimal:2',
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

    public function yacht(): BelongsTo
    {
        return $this->belongsTo(Yacht::class);
    }

    public function dates(): HasMany
    {
        return $this->hasMany(TripDate::class)->orderBy('date');
    }

    public function upcomingDates(): HasMany
    {
        return $this->dates()->where('date', '>=', today())->where('is_available', true);
    }

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'interested_in');
    }
}
