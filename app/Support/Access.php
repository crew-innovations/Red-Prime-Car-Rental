<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may do what (Schedule A14). Super Admin passes everything via Gate::before.
 * Users without a role are treated as Staff so nobody is locked out by surprise.
 */
class Access
{
    /** ability => roles allowed by default (changeable under Business settings) */
    public const ABILITIES = [
        // Leads, customers, pipeline
        'manage-leads' => ['Admin', 'Manager', 'Sales', 'Agent', 'Staff'],
        // Create / edit bookings (and pick their driver)
        'manage-bookings' => ['Admin', 'Manager', 'Sales', 'Agent', 'Operations', 'Staff'],
        // Hand over, receive, mark a car / yacht ready
        'operate-fleet' => ['Admin', 'Manager', 'Operations', 'Staff'],
        // Record money received
        'record-payments' => ['Admin', 'Manager', 'Sales', 'Agent', 'Operations', 'Finance'],
        // Refunds, deposit deductions, editing / deleting money records, expenses
        'manage-finance' => ['Admin', 'Manager', 'Finance'],
        // Upload, request and approve / reject customer documents
        'review-documents' => ['Admin', 'Manager', 'Sales', 'Agent', 'Operations', 'Staff'],
        // Create, send and convert quotations
        'manage-quotations' => ['Admin', 'Manager', 'Sales', 'Agent', 'Staff'],
        // Cars, yachts, trips, rates, publishing, website sections, lead sources
        'manage-fleet' => ['Admin', 'Manager'],
        // Pricing section: special rates, extras and fees, rate card (28 Oct)
        'manage-pricing' => ['Admin', 'Manager'],
        // Change a quoted / booked price, give a discount, waive a fee (always with a reason)
        'adjust-prices' => ['Admin', 'Manager', 'Sales', 'Agent'],
        // Delete leads, customers, bookings
        'delete-records' => ['Admin', 'Manager'],
        // Book or hand over a car / yacht that is in maintenance, unavailable or not ready
        'override-availability' => ['Admin', 'Manager'],
        // See who changed what (record history)
        'view-history' => ['Admin', 'Manager'],
        // Invite customers and drivers to log in, switch their accounts on / off
        'manage-accounts' => ['Admin', 'Manager'],
        // Driver profiles and their private documents (passport, ID, licence)
        'manage-drivers' => ['Admin', 'Manager'],
        // Review damage reported by drivers
        'review-damage' => ['Admin', 'Manager', 'Operations'],
        // Team, roles, business settings
        'manage-team' => ['Admin'],
    ];

    /** Plain-English names for the settings page. */
    public const LABELS = [
        'manage-leads' => 'Leads, customers and the pipeline',
        'manage-bookings' => 'Create and edit bookings',
        'operate-fleet' => 'Hand over, receive, mark cars / yachts ready',
        'record-payments' => 'Record money received',
        'manage-finance' => 'Refunds, deposits, edit / delete money records, expenses, accounts',
        'review-documents' => 'Upload, request and approve customer documents',
        'manage-quotations' => 'Create, send and convert quotations',
        'manage-fleet' => 'Cars, yachts, trips, rates, website publishing',
        'manage-pricing' => 'Pricing: special rates, extras and fees, the rate card',
        'adjust-prices' => 'Change a quoted or booked price, give a discount, waive a fee (a reason is always asked)',
        'delete-records' => 'Delete leads, customers, bookings, quotations',
        'override-availability' => 'Book / hand over a car that is in maintenance, not ready or missing documents',
        'view-history' => 'See the change history of records',
        'manage-accounts' => 'Invite customers and drivers to log in, switch accounts on / off',
        'manage-drivers' => 'Driver profiles and their documents (passport, ID, licence)',
        'review-damage' => 'Review damage reported by drivers',
        'manage-team' => 'Team, roles and business settings',
    ];

    /** Roles that can be given abilities (Super Admin always has everything). */
    public const ROLES = ['Admin', 'Manager', 'Sales', 'Agent', 'Operations', 'Finance', 'Staff'];

    /** Roles allowed an ability: from Business settings, else the defaults above. */
    public static function rolesFor(string $ability): array
    {
        $stored = \App\Models\Setting::read('role_abilities');
        $roles = is_array($stored) && array_key_exists($ability, $stored)
            ? (array) $stored[$ability]
            : (self::ABILITIES[$ability] ?? []);

        // Admin can never lock themselves out of the settings.
        if ($ability === 'manage-team' && ! in_array('Admin', $roles, true)) {
            $roles[] = 'Admin';
        }

        return $roles;
    }

    public static function register(): void
    {
        foreach (array_keys(self::ABILITIES) as $ability) {
            Gate::define($ability, function (User $user) use ($ability) {
                $roles = self::rolesFor($ability);
                $userRoles = $user->getRoleNames();

                return $userRoles->isEmpty()
                    ? in_array('Staff', $roles, true)
                    : $userRoles->intersect($roles)->isNotEmpty();
            });
        }
    }

    public static function allows(string $ability): bool
    {
        return Gate::allows($ability);
    }

    /** Drivers only see their own jobs and profile. */
    public static function isDriverOnly(): bool
    {
        return (bool) auth()->user()?->isDriverOnly();
    }

    /** Signed in as a customer account (only ever in the /account area). */
    public static function isCustomer(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->hasCrmRole();
    }

    /**
     * Agents see only their own work when Business settings says so: leads and
     * customers assigned to them, their quotations and bookings and the money of those.
     */
    public static function agentScoped(): bool
    {
        $user = auth()->user();
        if (! $user || ! (bool) \App\Models\Setting::read('agent_own_records_only', true)) {
            return false;
        }
        $roles = $user->getRoleNames()->diff(['Customer', 'Driver']);

        return $roles->isNotEmpty() && $roles->diff(['Agent'])->isEmpty();
    }

    /** Limit a query to the signed-in agent's own records (no change for everyone else). */
    public static function scopeOwn(\Illuminate\Database\Eloquent\Builder $query, string $kind): \Illuminate\Database\Eloquent\Builder
    {
        if (! self::agentScoped()) {
            return $query;
        }
        $id = auth()->id();
        $myCustomers = fn () => self::scopeOwn(\App\Models\Customer::withTrashed(), 'customer')->select('customers.id');

        return match ($kind) {
            'lead' => $query->where('assigned_to', $id),
            'customer' => $query->where(fn ($q) => $q->where('assigned_to', $id)
                ->orWhereIn('id', fn ($s) => $s->select('customer_id')->from('leads')->where('assigned_to', $id)->whereNotNull('customer_id'))
                ->orWhereIn('id', fn ($s) => $s->select('customer_id')->from('bookings')->where('assigned_to', $id)->whereNotNull('customer_id'))),
            'booking' => $query->where(fn ($q) => $q->where('assigned_to', $id)->orWhereIn('customer_id', $myCustomers())),
            'quotation' => $query->where(fn ($q) => $q->where('created_by', $id)->orWhereIn('customer_id', $myCustomers())
                ->orWhereIn('lead_id', fn ($s) => $s->select('id')->from('leads')->where('assigned_to', $id))),
            'transaction' => $query->where(fn ($q) => $q->where('user_id', $id)
                ->orWhereIn('booking_id', fn ($s) => $s->select('id')->from('bookings')->where('assigned_to', $id))
                ->orWhereIn('customer_id', $myCustomers())),
            'document' => $query->whereIn('customer_id', $myCustomers()),
            default => $query,
        };
    }
}
