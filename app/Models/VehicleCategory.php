<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Limousine vehicle class (Business sedan, Luxury SUV, Sprinter…): what the customer books and the rates use. */
class VehicleCategory extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_published' => 'boolean', 'sort_order' => 'integer', 'passengers' => 'integer', 'luggage' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (VehicleCategory $c) => $c->slug = Str::slug($c->slug ?: $c->name) ?: Str::random(8));
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TransferRate::class);
    }
}
