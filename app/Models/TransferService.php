<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A limousine / transfer service: airport pickup, hourly chauffeur, intercity… (brief 28 Oct-2, 4.1). */
class TransferService extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    public const JOURNEYS = [
        'one_way' => 'One way',
        'return' => 'Return journey',
        'hourly' => 'By the hour (chauffeur hire)',
        'custom' => 'Custom route',
    ];

    protected function casts(): array
    {
        return ['is_airport' => 'boolean', 'needs_destination' => 'boolean', 'is_active' => 'boolean', 'is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (TransferService $s) {
            $s->slug = Str::slug($s->slug ?: $s->name) ?: Str::random(8);
            if ($s->journey_type === 'hourly') {
                $s->needs_destination = false;
            }
        });
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function isHourly(): bool
    {
        return $this->journey_type === 'hourly';
    }
}
