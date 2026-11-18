<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** A guide or article on the website blog (redprimerentals.com/blog), managed in the CRM. */
class BlogPost extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (BlogPost $post) {
            $post->slug = Str::slug($post->slug ?: $post->title) ?: Str::random(8);
            if ($post->is_published && ! $post->published_at) {
                $post->published_at = now();
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(800)->height(500)->nonQueued();
        $this->addMediaConversion('large')->width(1600)->nonQueued();
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true)->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** A field in the page language: the Arabic version when there is one, otherwise English. */
    public function local(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if ($locale === 'ar' && filled($this->{$field.'_ar'} ?? null)) {
            return $this->{$field.'_ar'};
        }

        return $this->{$field};
    }

    /** Minutes to read, from the body in the page language. */
    public function readMinutes(?string $locale = null): int
    {
        $words = str_word_count(strip_tags((string) $this->local('body', $locale)));
        if ($words === 0) {
            $words = count(preg_split('/\s+/u', trim(strip_tags((string) $this->local('body', $locale)))) ?: []);
        }

        return max(1, (int) ceil($words / 200));
    }
}
