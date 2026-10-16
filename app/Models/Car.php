<?php

namespace App\Models;

use App\Enums\CarStatus;
use App\Models\Concerns\PublishesToWebsite;
use Illuminate\Database\Eloquent\Builder;
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

    /** Days before expiry at which documents are flagged in the CRM. */
    public const EXPIRY_WARNING_DAYS = 30;

    /** Registration, insurance or service due within the warning window (or overdue). */
    public function scopeDocumentsDueSoon(Builder $query): Builder
    {
        $limit = today()->addDays(self::EXPIRY_WARNING_DAYS);

        return $query->where(fn (Builder $q) => $q
            ->where('registration_expiry', '<=', $limit)
            ->orWhere('insurance_expiry', '<=', $limit)
            ->orWhere('next_service_date', '<=', $limit));
    }

    /** @return array<string, string> label => human message, for items needing attention */
    public function documentAlerts(): array
    {
        $alerts = [];

        foreach ([
            'Registration' => $this->registration_expiry,
            'Insurance' => $this->insurance_expiry,
            'Service' => $this->next_service_date,
        ] as $label => $date) {
            if (! $date) {
                continue;
            }

            $days = (int) today()->diffInDays($date, false);

            if ($days < 0) {
                $alerts[$label] = "{$label} overdue by ".abs($days).' days';
            } elseif ($days <= self::EXPIRY_WARNING_DAYS) {
                $alerts[$label] = "{$label} due in {$days} days";
            }
        }

        return $alerts;
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
