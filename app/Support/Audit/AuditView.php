<?php

namespace App\Support\Audit;

use App\Filament\Resources;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * 19 Nov (Bilal, item 6): how audit log lines are shown. Everything here is safe for old or odd
 * lines: a line whose record type no longer exists, a deleted record, a value that cannot be read.
 * One such line used to stop the whole Audit log screen (the list loaded every record in one go,
 * and an unknown record type is a fatal error); now it just shows as "unknown".
 */
class AuditView
{
    /** Record type → [module label, resource that opens it (or null)]. */
    public const MODULES = [
        'lead' => ['Leads', Resources\LeadResource::class],
        'customer' => ['Customers', Resources\CustomerResource::class],
        'customer_document' => ['Customer documents', null],
        'quotation' => ['Quotations', Resources\QuotationResource::class],
        'booking' => ['Bookings', Resources\BookingResource::class],
        'rental_contract' => ['Contracts', Resources\RentalContractResource::class],
        'contract_driver' => ['Contracts', null],
        'contract_vehicle_swap' => ['Contracts', null],
        'booking_charge' => ['Bookings', null],
        'booking_job' => ['Driver jobs', null],
        'damage_report' => ['Damage reports', null],
        'deposit_settlement' => ['Deposits', null],
        'deposit_preauth' => ['Deposits', null],
        'transaction' => ['Accounts', Resources\TransactionResource::class],
        'invoice' => ['Invoices', Resources\InvoiceResource::class],
        'payment_request' => ['Online payments', null],
        'car' => ['Cars', Resources\CarResource::class],
        'yacht' => ['Yachts', Resources\YachtResource::class],
        'trip' => ['Yacht trips', Resources\TripResource::class],
        'package' => ['Trips & packages', Resources\PackageResource::class],
        'fuel_entry' => ['Fuel', null],
        'traffic_fine' => ['Fines & tolls', Resources\TrafficFineResource::class],
        'toll_trip' => ['Fines & tolls', null],
        'toll_account' => ['Fines & tolls', null],
        'supplier' => ['Suppliers', Resources\SupplierResource::class],
        'supplier_rate' => ['Suppliers', null],
        'supplier_payment' => ['Suppliers', null],
        'transfer_service' => ['Limousine & transfers', null],
        'transfer_rate' => ['Limousine & transfers', null],
        'transfer_fixed_route' => ['Limousine & transfers', null],
        'vehicle_category' => ['Limousine & transfers', null],
        'price_rule' => ['Prices', null],
        'price_extra' => ['Prices', null],
        'user' => ['Team & logins', Resources\UserResource::class],
        'driver_profile' => ['Drivers', null],
        'driver_document' => ['Drivers', null],
        'driver_unavailability' => ['Drivers', null],
        'job_report' => ['Job reports', null],
        'lead_form' => ['Website & forms', null],
        'blog_post' => ['Website & forms', null],
        'mail_account' => ['Email', null],
        'email_template' => ['Email', null],
        'integration' => ['Integrations', null],
    ];

    /** Events that are not about one record, shown under these modules. */
    public const EVENT_MODULES = [
        'login' => 'Sign-ins', 'logout' => 'Sign-ins', 'login_failed' => 'Sign-ins',
        'settings' => 'Settings', 'permissions' => 'Settings', 'integration' => 'Integrations',
        'export' => 'Exports', 'email' => 'Email', 'app' => 'Team & logins', 'push' => 'Team & logins',
    ];

    /** Field names shown in plain words. */
    public const FIELDS = [
        'bookable_id' => 'car / yacht', 'bookable_type' => 'type', 'customer_id' => 'customer', 'user_id' => 'user', 'assigned_to' => 'agent',
        'created_by' => 'made by', 'updated_by' => 'changed by', 'driver_id' => 'driver', 'car_id' => 'car', 'booking_id' => 'booking', 'lead_id' => 'lead',
        'quotation_id' => 'quotation', 'supplier_id' => 'supplier', 'start_at' => 'start', 'end_at' => 'end', 'base_amount' => 'price',
        'extras_amount' => 'extras', 'security_deposit' => 'deposit', 'is_active' => 'active', 'login_enabled' => 'can log in',
    ];

    /** *_id fields shown with the name of the record. */
    protected const LINKS = [
        'customer_id' => \App\Models\Customer::class, 'user_id' => \App\Models\User::class, 'assigned_to' => \App\Models\User::class,
        'created_by' => \App\Models\User::class, 'updated_by' => \App\Models\User::class, 'driver_id' => \App\Models\User::class,
        'car_id' => \App\Models\Car::class, 'booking_id' => \App\Models\Booking::class, 'lead_id' => \App\Models\Lead::class,
        'quotation_id' => \App\Models\Quotation::class, 'supplier_id' => \App\Models\Supplier::class,
    ];

    /** @var array<string, ?Model> */
    protected static array $records = [];

    /** @var array<string, ?string> */
    protected static array $names = [];

    public static function flush(): void
    {
        self::$records = [];
        self::$names = [];
    }

    /** The model class for a record type, or null when it no longer exists. */
    public static function classFor(?string $type): ?string
    {
        if (! $type) {
            return null;
        }
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    public static function module(AuditLog $log): string
    {
        if (in_array($log->event, ['login', 'logout', 'login_failed'], true)) {
            return 'Sign-ins';
        }
        if ($log->auditable_type) {
            return self::MODULES[$log->auditable_type][0]
                ?? (self::classFor($log->auditable_type) ? ucfirst(str_replace('_', ' ', class_basename((string) $log->auditable_type))) : 'Other');
        }

        return self::EVENT_MODULES[$log->event] ?? 'System';
    }

    /** Module filter options: label => list of record types (and events). */
    public static function moduleOptions(): array
    {
        $out = [];
        foreach (self::MODULES as $type => [$label]) {
            $out[$label]['types'][] = $type;
        }
        foreach (self::EVENT_MODULES as $event => $label) {
            $out[$label]['events'][] = $event;
        }
        ksort($out);

        return $out;
    }

    /** The record of a line (deleted ones too), loaded once per page. Never throws. */
    public static function record(AuditLog $log): ?Model
    {
        if (! $log->auditable_type || ! $log->auditable_id) {
            return null;
        }
        $key = $log->auditable_type.':'.$log->auditable_id;
        if (array_key_exists($key, self::$records)) {
            return self::$records[$key];
        }
        $class = self::classFor($log->auditable_type);
        try {
            $query = $class ? $class::query() : null;
            if ($query && in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withTrashed();
            }

            return self::$records[$key] = $query?->find($log->auditable_id);
        } catch (\Throwable) {
            return self::$records[$key] = null;
        }
    }

    public static function recordLabel(AuditLog $log): string
    {
        if (! $log->auditable_type) {
            return '—';
        }
        if (! $log->auditable_id) {
            return 'All';
        }
        $item = self::record($log);
        $name = $item ? self::nameOf($item) : null;

        return ($name ?: '#'.$log->auditable_id).($item === null && $log->event !== 'deleted' ? ' (removed)' : '');
    }

    public static function recordUrl(AuditLog $log): ?string
    {
        $resource = self::MODULES[$log->auditable_type][1] ?? null;
        $item = $resource ? self::record($log) : null;
        if (! $item || $log->event === 'deleted' || (method_exists($item, 'trashed') && $item->trashed())) {
            return null;
        }
        try {
            // Not every list has an edit page (Invoices has only its list): linking to a missing page was the
            // live "Route [...invoices.edit] not defined" error that stopped the Audit log.
            return match (true) {
                $resource::hasPage('edit') => $resource::getUrl('edit', ['record' => $item]),
                $resource::hasPage('view') => $resource::getUrl('view', ['record' => $item]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function nameOf(Model $item): ?string
    {
        foreach (['reference', 'number', 'name', 'title', 'ticket_no', 'email', 'label'] as $attr) {
            try {
                $v = $item->getAttributes()[$attr] ?? null; // raw value: never decrypts
                if (is_string($v) && $v !== '' && ! ($attr === 'number' && $item->hasCast('number', ['encrypted']))) {
                    return mb_strimwidth($v, 0, 60, '…');
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /** Name of a linked record (customer_id → "John Smith"). */
    protected static function linked(string $field, $id): ?string
    {
        $class = self::LINKS[$field] ?? null;
        if (! $class || ! is_scalar($id) || ! is_numeric($id)) {
            return null;
        }
        $key = $class.':'.$id;
        if (! array_key_exists($key, self::$names)) {
            try {
                $q = $class::query();
                if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                    $q->withTrashed();
                }
                $m = $q->find($id);
                self::$names[$key] = $m ? self::nameOf($m) : null;
            } catch (\Throwable) {
                self::$names[$key] = null;
            }
        }

        return self::$names[$key];
    }

    public static function fieldLabel(string $field): string
    {
        return self::FIELDS[$field] ?? str_replace('_', ' ', $field);
    }

    public static function value(string $field, $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'empty';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = (string) $value;
        if ($name = self::linked($field, $value)) {
            return $name;
        }
        if (preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d)?/', $value)) {
            try {
                return Carbon::parse($value)->timezone(config('app.timezone'))->format('d M Y H:i');
            } catch (\Throwable) {
            }
        }

        return mb_strimwidth($value, 0, 120, '…');
    }

    /** [field label, old, new] for each change of a line (hidden fields left out). */
    public static function changes(AuditLog $log): array
    {
        $rows = [];
        try {
            $old = (array) $log->old_values;
            foreach ((array) $log->new_values as $field => $new) {
                $field = (string) $field;
                if ($field === 'number' && $log->auditable_type === 'driver_document') {
                    $rows[] = [self::fieldLabel($field), 'hidden', 'hidden'];

                    continue;
                }
                $rows[] = [self::fieldLabel($field), self::value($field, $old[$field] ?? null), self::value($field, $new)];
            }
            if ($rows === [] && $old !== []) { // deleted lines can carry the old values only
                foreach ($old as $field => $value) {
                    $rows[] = [self::fieldLabel((string) $field), self::value((string) $field, $value), '—'];
                }
            }
        } catch (\Throwable) {
            $rows[] = ['details', 'could not be shown', ''];
        }

        return $rows;
    }

    /** Short HTML for the list: the note, then up to $max changes. */
    public static function summary(AuditLog $log, int $max = 4): string
    {
        $lines = [];
        if ($log->note) {
            $lines[] = e($log->note);
        }
        $changes = self::changes($log);
        foreach (array_slice($changes, 0, $max) as [$field, $old, $new]) {
            $lines[] = $log->event === 'created'
                ? '<b>'.e($field).'</b>: '.e($new)
                : '<b>'.e($field).'</b>: '.e($old).' → '.e($new);
        }
        if (count($changes) > $max) {
            $lines[] = '<span style="opacity:.7">+ '.(count($changes) - $max).' more (open the line)</span>';
        }
        if (! $lines) {
            $lines[] = match ($log->event) {
                'created' => 'Created', 'deleted' => 'Deleted', 'restored' => 'Restored', default => '—',
            };
        }

        return implode('<br>', $lines);
    }
}
