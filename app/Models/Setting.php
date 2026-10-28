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
        'hourly_max_hours' => 8,
        'weekly_from_days' => 7,
        'monthly_from_days' => 28,
        'yacht_half_day_hours' => 4,
        'yacht_full_day_from_hours' => 8,
        // Pricing (28 Oct)
        'hourly_increment_minutes' => 60,     // hourly rates bill in steps of this many minutes
        'price_max_discount_percent' => null, // empty = no limit (Bilal, 28 Oct: no approval step)
        // Accounts (26 Oct): who can log in and how invites work
        'agent_own_records_only' => true,
        'customer_accounts_enabled' => true,
        'customer_can_upload_documents' => true,
        'customer_sees_payments' => true,
        'customer_sees_quotations' => true,
        'invite_link_hours' => 72,
        // Drivers (26 Oct). Numbers marked "to confirm" are starting values only.
        'driver_login_enabled' => true,
        'driver_self_service' => false,
        'driver_editable_fields' => ['phone', 'emergency_name', 'emergency_phone'],
        'driver_sees_customer_phone' => true,
        'driver_sees_instructions' => true,
        'driver_steps' => ['acknowledged', 'on_the_way', 'arrived'],
        'driver_can_handover' => true,
        'driver_can_return' => true,
        'driver_can_report_damage' => true,
        'handover_require_km' => true,
        'handover_require_fuel' => true,
        'handover_min_photos' => 1,       // to confirm
        'return_require_km' => true,
        'return_require_fuel' => true,
        'return_min_photos' => 1,         // to confirm
        'checklist_all_required' => false,
        'handover_checklist' => ['Walk-around done with the customer', 'Fuel level checked', 'Car documents in the car', 'Keys handed over'],
        'return_checklist' => ['Walk-around done with the customer', 'Fuel level checked', 'Car documents in the car', 'Keys received'],
        'driver_job_gap_minutes' => 90,   // to confirm
        'driver_required_fields' => [],
        'driver_required_documents' => [],
        'driver_block_incomplete' => false,
        'driver_doc_warn_days' => 30,     // to confirm
        'driver_doc_reminders' => true,
        'notify_drivers_by_email' => false,
        'notify_staff_by_email' => false,
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

    /** Forget cached values (after direct DB changes, and between tests). */
    public static function flushCache(): void
    {
        Cache::forget('crm.settings');
        static::$memo = null;
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
