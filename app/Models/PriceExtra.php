<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catalogue of extras and fees that are not tied to one car or yacht:
 * delivery and collection fees, an extra driver, decorations, catering...
 * Add-ons saved on a car / yacht / trip itself still work as before.
 */
class PriceExtra extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    public const KINDS = [
        'extra' => 'Optional extra',
        'delivery' => 'Delivery fee',
        'collection' => 'Collection fee',
        'fee' => 'Always-added fee',
    ];

    public const UNITS = [
        'per_booking' => 'Per booking',
        'per_day' => 'Per day',
        'per_hour' => 'Per hour',
        'per_guest' => 'Per guest',
    ];

    protected function casts(): array
    {
        return [
            'service_types' => 'array',
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForService(Builder $query, ?string $service): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('service_types')
            ->orWhereJsonLength('service_types', 0)
            ->orWhereJsonContains('service_types', (string) $service));
    }

    public function appliesTo(?string $service): bool
    {
        return empty($this->service_types) || in_array($service, $this->service_types, true);
    }
}
