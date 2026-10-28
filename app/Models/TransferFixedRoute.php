<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A fixed price for a known route (e.g. DXB airport → Dubai Marina) and vehicle category. */
class TransferFixedRoute extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'both_ways' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }

    /** Any of the comma-separated words appears in the address (not case sensitive). */
    public static function matches(string $words, ?string $address): bool
    {
        $address = mb_strtolower((string) $address);
        if ($address === '') {
            return false;
        }

        return collect(explode(',', $words))->map(fn ($w) => mb_strtolower(trim($w)))->filter()
            ->contains(fn ($w) => str_contains($address, $w));
    }

    public static function forRoute(?int $categoryId, ?string $from, ?string $to): ?self
    {
        if (! $categoryId) {
            return null;
        }

        return static::query()->where('vehicle_category_id', $categoryId)->where('is_active', true)->orderBy('price')->get()
            ->first(fn (self $r) => (self::matches($r->from_match, $from) && self::matches($r->to_match, $to))
                || ($r->both_ways && self::matches($r->from_match, $to) && self::matches($r->to_match, $from)));
    }
}
