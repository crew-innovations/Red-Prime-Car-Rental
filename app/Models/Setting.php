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
        // Company profile for every PDF (brief 4 Nov, 12.1). Empty until Bilal fills them in.
        'company_whatsapp' => null,
        'company_website' => null,
        'company_logo' => null,        // path on the public disk; empty = the built-in logo
        'company_logo_dark' => null,
        'company_stamp' => null,       // private disk
        'bank_name' => null,
        'bank_account_name' => null,
        'bank_iban' => null,
        'bank_swift' => null,
        'vat_enabled' => false,
        'vat_rate' => null,
        'vat_inclusive' => false,
        'quote_valid_days' => 7,        // 6 Nov (S4): H-37 default until Bilal answers (Everyday settings)
        'quote_terms' => null,
        'upload_link_hours' => 72,
        'doc_requirements' => [],       // booking kind => [document types]
        'doc_block_handover' => false,  // only blocks once requirements are set
        // Bookings & returns (defaults = how the CRM worked before these were editable)
        'pending_payment_holds' => true,
        'website_booking_hold_hours' => 24, // Bilal, 30 Oct: an unpaid website booking is cancelled after 24 hours
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
        'price_max_discount_percent' => null, // empty = no limit (Bilal, 28 Oct)
        'price_approval_required' => false,   // 28 Oct-2: above the limit, wait for a manager instead of refusing (off)
        // Discount limits per role (brief 4 Nov, 10). Empty % = no limit. Above the role's limit the price waits
        // for a manager, even with price approval switched off. Empty list = the single limit above (before 4 Nov).
        // 5 Nov: the four roles. Accounts can't change prices, so it needs no row.
        'discount_limits' => [
            ['role' => 'Agent', 'percent' => 10],
            ['role' => 'Admin', 'percent' => null],
        ],
        'discount_reason_from_percent' => 5,   // a reason is needed for a discount above this % (any other change always)
        'discount_stacks_with_rules' => false, // a discount on top of a special rate
        // Advance to confirm (brief 4 Nov, 11): % of the booking total per service. Empty = no advance rule.
        'advance_percent' => ['car' => 30, 'yacht' => 50, 'trip' => 100, 'package' => 50, 'transfer' => 100],
        'advance_min_percent' => null,         // below this needs "Approve prices" and a reason
        'balance_due_rule' => 'standard',      // standard (cars on return, others at the start) / before_start / on_delivery / on_return
        'balance_due_hours' => 48,             // with before_start
        'confirm_on_advance' => true,          // Pending payment → Confirmed once the advance is paid
        // Phase 2 (4 Nov): email accounts, notifications, apps, customer portal accounts.
        'mail_routes' => [],                   // routing type => mail account id (empty = default mailbox)
        'email_log_retention_days' => 90,      // email bodies kept this long (brief 14.4)
        'announcement_per_hour' => 100,        // per mailbox, when the mailbox has no own limit (starting value)
        'notification_channels' => [],         // role => channels (empty = App\Support\Notifications\Preferences::ROLE_DEFAULTS)
        'lead_accept_minutes' => 60,           // 5 Nov (H-35): remind all agents and tell Admin when a lead waits this long (empty = off)
        'job_soon_minutes' => 60,              // "job starts in 1 hour" (brief 9.3)
        'quote_expiry_customer_reminder' => false, // brief 9.3: optional
        'followup_enabled' => true,            // 12 Nov (Bilal): follow up by itself when a customer does not answer
        'followup_hours' => '2, 24, 72',       // hours after sending (starting values, Bilal approved 12 Nov)
        'followup_quiet_from' => 22,           // no customer reminders from this hour...
        'followup_quiet_to' => 8,              // ...until this hour (Dubai time)
        'chat_welcome_enabled' => true,        // 12 Nov (C4): first automatic reply to a new chat enquiry (text editable; default in AutoReply)
        'chat_welcome_form' => true,           // ...with the details form link when an open form exists
        'chat_closed_enabled' => false,        // "we are closed" reply: off until Bilal sets the working hours
        'chat_hours_from' => null,
        'chat_hours_to' => null,
        'balance_reminder_days' => 2,          // "balance due soon" this many days before (starting value)
        'late_return_customer_reminder' => true,
        'pwa_apps' => [],                      // Settings › Apps (brief 16.1)
        'portal_on_lead' => false,             // H-25 open: the plan's recommendation (from the quotation on) until Bilal decides
        'portal_on_quote' => true,
        'portal_on_booking' => true,
        'portal_new_device_email' => false,    // brief 13.5: off by default
        'disposable_email_domains' => ['mailinator.com', 'guerrillamail.com', 'yopmail.com', '10minutemail.com', 'tempmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'temp-mail.org', 'dispostable.com'],
        // Phase 3 (4 Nov, 15): driver portal.
        'job_accept_minutes' => null,          // tell the office when a new job is not accepted in this time (empty = off)
        'driver_can_decline' => true,          // the driver can decline a job with a reason; the office reassigns it
        'driver_updates_to_customer' => true,  // "your driver is on the way" / "running about 15 minutes late"
        'handover_signature' => 'optional',    // off / optional / required: the customer signs on the driver's phone
        'limo_location_during_trips' => true,  // 6 Nov (H-48 default): limousine trips share location from "On my way" to "Dropped off" only
        'limo_distance_only' => true,          // 11 Nov (Bilal): website limousine is priced by distance only, no service to choose
        'limo_agent_confirms' => true,         // 11 Nov (Bilal): the website price is an estimate; an agent confirms it before payment
        'limo_airport_free_wait' => 60,        // 6 Nov (H-50 default): free waiting at airport pickups, minutes
        'driver_location_enabled' => false,    // H-23 open: off until Bilal decides and drivers are told in writing
        'location_retention_days' => 30,       // brief: 30 days
        'location_interval_seconds' => 60,
        'driver_languages' => ['en', 'ur', 'ar'], // H-31: Hindi on request
        // Phase 4 (4 Nov): fines, tolls, deposits. Empty = no fee / no hold until Bilal decides (H-13 to H-16).
        'fine_admin_fee_mode' => 'fixed',      // fixed / percent
        'fine_admin_fee_amount' => null,       // H-13: empty = no admin fee
        'fine_admin_fee_min' => null,
        'toll_service_fee' => [],              // H-14: per type (salik, darb, parking), empty = none
        'toll_auto_charge' => true,            // matched trips go on the customer's booking straight away
        'deposit_hold_days' => null,           // H-15: brief suggests 21; empty = off (refund as before)
        // Sales workflow (28 Oct). Off keeps the way staff worked before.
        'booking_requires_accepted_quote' => false,
        'doc_block_confirm' => false,
        // 18 Nov (meeting minutes): rental contracts and the booking form. Money amounts stay empty
        // until RED PRIME decides them (decisions C-1 to C-11); nothing is guessed.
        'delivery_fee_default' => 'off',     // item 2: off = the agent / admin switches the charge on per booking; price_list = as before
        'no_deposit_allowed' => true,        // item 4: "No deposit" can be picked on a booking
        'no_deposit_fee_mode' => 'off',      // off / fixed / per_day / percent (of the rental). C-3 open
        'no_deposit_fee_amount' => null,
        'confirm_requires_details' => true,  // item 8: a car booking is confirmed only with the customer details and documents complete
        'confirm_required_fields' => ['name', 'phone', 'nationality', 'residency', 'id_or_passport', 'licence_number', 'licence_expires_on'],
        'contract_number_prefix' => 'RC',    // C-8: RC-2026-00001
        'contract_terms_en' => null,         // C-1: RED PRIME's own terms (English)
        'contract_terms_ar' => null,         // C-1: Arabic
        'contract_required_for_handover' => false, // hand over only with a signed contract (off until terms are in)
        'contract_sign_link_hours' => 48,
        'driver_min_age' => null,            // C-4: empty = not checked
        'licence_min_years' => null,         // C-4: empty = not checked
        'additional_driver_charge' => null,  // per driver per contract; empty = typed per contract
        'contract_invoice_mode' => 'advance', // C-10: monthly contracts invoiced at the start (advance) or end (arrears) of each period
        'contract_invoice_due_days' => 7,
        'contract_renewal_days' => 7,
        // 19 Nov (suggestions 1–10, Bilal: "do 1-10")
        'block_expired_car_papers' => true,  // a car with expired registration / insurance is not handed over (manager override as usual)
        'bank_accounts' => [],               // [{key, name, bank, iban, kind: bank|cash}] money is received into / paid from
        'tax_emirate_default' => 'dubai',    // emirate of supply for the VAT report (bookings can change it)
        'marketplaces' => ['OneClickDrive', 'Yango Drive', 'Rentop', 'Cardoo', 'Getaround'],
        'borrow_terms' => null,              // suggestion 7: terms printed on borrow agreements with suppliers
        'contract_sign_otp' => 'off',        // off / email: the customer types a code sent by email before signing by link        // remind this many days before a contract ends
        'transfer_km_rounding' => '0.1',     // Bilal, 29 Oct: road distance billed to 0.1 km
        'lead_assignment' => 'broadcast',    // 5 Nov: broadcast (every agent, first to accept) / manual / round_robin / by_service
        'leads_repeat_to_owner' => true,     // 5 Nov: a lead from a customer who has an agent goes straight to that agent
        'agents_can_cancel_unpaid' => true,  // 5 Nov (H-36): agents cancel unpaid bookings themselves; Admin is always told
        'refuel_fee' => null,               // 5 Nov (S5, H-41): Admin decides; empty = no fee on top of the fuel itself
        'fuel_warning_percent' => 25,       // warn when a car's km per litre drops this much below its usual
        'fuel_receipt_required' => true,    // drivers add a photo of the receipt with every fill-up
        'lead_assignment_people' => [],
        'lead_assignment_by_service' => [],  // [{service, user_id}]
        'payment_link_days' => null,         // empty = a payment link stays open until paid or cancelled (28 Oct-2)
        'audit_retention_days' => null,      // empty = keep the audit log forever (28 Oct-2)
        'expense_approval_required' => true, // expenses from staff without finance rights wait for approval
        // Accounts (26 Oct): who can log in and how invites work
        'agent_own_records_only' => true,
        // 5 Nov, four roles: where old "Operations" users go (Admin or Agent, decision H-33) and the
        // role matrix changed under Settings › Advanced (empty = the defaults in App\Support\Access).
        'legacy_operations_role' => 'Admin',
        'role_abilities_v2' => null,
        'customer_accounts_enabled' => true,
        'customer_can_upload_documents' => true,
        'account_documents_gate' => true, // 19 Nov: required documents first, then the rest of the account
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
        'handover_requires_approval' => false, // 28 Oct: office OK before the car is released
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
        // Public website (29 Oct): contact details and social links Bilal gave. Editable in Business settings.
        'website_phone' => '+971 55 793 9231',
        'website_whatsapp' => '+971 55 793 9231',
        'website_email' => 'info@redprimerentals.com',
        'website_address' => null,
        'website_map_url' => 'https://maps.app.goo.gl/XoaQrsFum1xXyKuy9',
        'website_instagram' => 'https://www.instagram.com/redprimerentals',
        'website_facebook' => 'https://www.facebook.com/share/1DYme9sh71/',
        'website_tiktok' => 'https://www.tiktok.com/@redprimerentals',
        'website_snapchat' => 'https://snapchat.com/t/SFsGWuYA',
        'website_offers' => [],   // 30 Oct: offers entered by staff (Website offers & reviews)
        'website_reviews' => [],  // 30 Oct: real customer reviews entered by staff
    ];

    /** Per-request copy, so permission checks don't hit the cache every time. */
    protected static ?array $memo = null;

    public static function values(): array
    {
        // 12 Nov: the defaults are laid under the cached values too, so a default added by an update
        // shows at once, even where the cache was not cleared.
        return static::$memo ??= array_replace(self::DEFAULTS, (array) Cache::rememberForever('crm.settings', function () {
            $stored = Schema::hasTable('settings')
                ? static::query()->pluck('value', 'key')->all()
                : [];

            return array_replace(self::DEFAULTS, $stored);
        }));
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
        $changed = [];
        foreach ($values as $key => $value) {
            $row = static::firstOrNew(['key' => $key]);
            $before = $row->exists ? $row->value : null;
            $row->value = $value;
            if (! $row->exists || $row->isDirty('value')) {
                $changed[$key] = [$before, $value];
            }
            $row->save();
        }
        // 19 Nov (audit log): settings changed by a person are recorded (secrets are masked by AuditLog).
        if ($changed && auth()->check()) {
            rescue(fn () => AuditLog::create([
                'event' => 'settings',
                'note' => mb_substr('Settings changed: '.implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($changed))), 0, 255),
                'old_values' => array_map(fn ($c) => $c[0], $changed),
                'new_values' => array_map(fn ($c) => $c[1], $changed),
            ]), report: false);
        }
        Cache::forget('crm.settings');
        static::$memo = null;
    }
}
