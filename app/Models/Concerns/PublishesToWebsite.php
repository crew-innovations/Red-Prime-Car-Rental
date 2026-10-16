<?php

namespace App\Models\Concerns;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Shared by Car, Yacht and Trip: the CRM is the single source of truth for the
 * website (Clause 4). An item is public only when it is published AND its
 * website category is enabled.
 *
 * Models using this trait must define: public const CATEGORY = '<category-slug>';
 */
trait PublishesToWebsite
{
    public static function bootPublishesToWebsite(): void
    {
        // New items go to the end of the website order.
        static::creating(function ($model) {
            if (! $model->sort_order) {
                $model->sort_order = (int) static::withTrashed()->max('sort_order') + 1;
            }
        });

        static::saving(function ($model) {
            if (blank($model->slug)) {
                $model->slug = static::uniqueSlug($model->name, $model->getKey());
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::random(8);
        $slug = $base;
        $i = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** What the public website is allowed to show. */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->published()
            ->when(! Category::isEnabled(static::CATEGORY), fn (Builder $q) => $q->whereRaw('1 = 0'));
    }

    public function isPubliclyVisible(): bool
    {
        return $this->is_published && Category::isEnabled(static::CATEGORY);
    }
}
