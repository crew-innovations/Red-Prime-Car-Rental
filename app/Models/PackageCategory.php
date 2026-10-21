<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Group for Trips & Packages on the website (e.g. Desert Safaris, Mountain Escapes). */
class PackageCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (PackageCategory $category) {
            if (blank($category->slug)) {
                $base = Str::slug($category->name) ?: Str::random(6);
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->whereKeyNot($category->getKey())->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $category->slug = $slug;
            }
        });
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
