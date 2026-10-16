<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Simple key/value settings managed under Settings › Business settings. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public const DEFAULTS = [
        'company_name' => 'RED PRIME RENTALS',
        'vat_enabled' => false,
        'vat_rate' => null,
        'vat_inclusive' => false,
        'quote_valid_days' => null,
        'quote_terms' => null,
        'upload_link_hours' => 72,
        'doc_requirements' => [],       // booking kind => [document types]
        'doc_block_handover' => false,  // only blocks once requirements are set
        // Bookings & returns (defaults = how the CRM worked before these were editable)
        'pending_payment_holds' => true,
        'booking_buffer_minutes' => 0,
        'late_grace_minutes' => 30,
        'late_charge_mode' => 'manual',
        'late_charge_amount' => null,
        'car_default_hours' => 24,
        'yacht_default_hours' => 4,
        'trip_default_minutes' => 120,
    ];

    /** Per-request copy, so permission checks don't hit the cache every time. */
    protected static ?array $memo = null;

    public static function values(): array
    {
        return static::$memo ??= Cache::rememberForever('crm.settings', function () {
            $stored = Schema::hasTable('settings')
                ? static::query()->pluck('value', 'key')->all()
                : [];

            return array_replace(self::DEFAULTS, $stored);
        });
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        return static::values()[$key] ?? $default;
    }

    public static function write(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget('crm.settings');
        static::$memo = null;
    }
}
