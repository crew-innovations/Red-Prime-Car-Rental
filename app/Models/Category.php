<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    public const CARS = 'cars';
    public const YACHTS = 'yachts';
    public const YACHT_TRIPS = 'yacht-trips';

    protected $fillable = ['name', 'slug', 'is_enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('categories.enabled'));
        static::deleted(fn () => Cache::forget('categories.enabled'));
    }

    /** @return array<string, bool> slug => enabled */
    public static function enabledMap(): array
    {
        return Cache::rememberForever('categories.enabled', fn () => static::pluck('is_enabled', 'slug')
            ->map(fn ($v) => (bool) $v)
            ->all());
    }

    public static function isEnabled(string $slug): bool
    {
        // Unknown category = enabled, so a missing seed never hides content.
        return static::enabledMap()[$slug] ?? true;
    }
}
