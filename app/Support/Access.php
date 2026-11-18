<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may do what (Schedule A14). Super Admin passes everything via Gate::before.
 * Users without a role are treated as Agent so nobody is locked out by surprise.
 */
class Access
{
    /**
     * ability => roles allowed by default. 5 Nov ("Make it simple", Part A): four staff roles.
     * Admin = owner / manager, Agent = sales person who follows a lead to the end (own records only),
     * Accounts = finance, Driver = own jobs only (phone screens, no abilities here).
     * Old role names still work through LEGACY until the role migration has moved everyone.
     */
    public const ABILITIES = [
        // Leads, customers, pipeline
        'manage-leads' => ['Admin', 'Agent'],
        // Give a lead to another agent, reassign a whole customer
        'assign-leads' => ['Admin'],
        // Create / edit bookings (and pick their driver). Agents: their own bookings only.
        'manage-bookings' => ['Admin', 'Agent'],
        // Hand over, receive, mark a car / yacht ready. Agents: their own bookings only.
        'operate-fleet' => ['Admin', 'Agent'],
        // Record money received
        'record-payments' => ['Admin', 'Agent', 'Accounts'],
        // Refunds, deposit deductions, editing / deleting money records, expenses, accounts and reports
        'manage-finance' => ['Admin', 'Accounts'],
        // Enter an operating expense (it waits for Accounts unless entered by Accounts / Admin)
        'record-expenses' => ['Admin', 'Agent', 'Accounts'],
        // Upload, request and approve / reject customer documents
        'review-documents' => ['Admin', 'Agent'],
        // Create, send and convert quotations
        'manage-quotations' => ['Admin', 'Agent'],
        // Cars, yachts, trips, rates, publishing, website sections, lead sources
        'manage-fleet' => ['Admin'],
        // Pricing section: special rates, extras and fees, rate card
        'manage-pricing' => ['Admin'],
        // Change a quoted / booked price, give a discount (within the role's limit), waive a fee
        'adjust-prices' => ['Admin', 'Agent'],
        // OK a price above the discount limit
        'approve-prices' => ['Admin'],
        // Archive (soft delete) leads, customers, bookings, quotations
        'delete-records' => ['Admin'],
        // Book or hand over a car / yacht that is in maintenance, unavailable or not ready
        'override-availability' => ['Admin'],
        // See who changed what (record history)
        'view-history' => ['Admin'],
        // Invite customers and drivers to log in, switch their accounts on / off
        'manage-accounts' => ['Admin'],
        // Driver profiles and their private documents (passport, ID, licence)
        'manage-drivers' => ['Admin'],
        // Review damage reported by drivers
        'review-damage' => ['Admin'],
        // Download lists as Excel / CSV. Each export is logged.
        'export-data' => ['Admin', 'Accounts'],
        // Outside services: Google Maps, payment gateways, social platforms (keys, tests)
        'manage-integrations' => ['Admin'],
        // Suppliers and their cost rates; payments, cost overrides and profit stay with manage-finance.
        'manage-suppliers' => ['Admin', 'Accounts'],
        // Email templates and announcements; the installable apps and their devices
        'manage-email' => ['Admin'],
        'manage-apps' => ['Admin'],
        // Give jobs to drivers on the dispatch board; see where drivers are (only when switched on)
        'dispatch-drivers' => ['Admin'],
        'track-drivers' => ['Admin'],
        // Traffic fines, tolls, toll accounts and statement imports (deposit settlement stays with manage-finance)
        'manage-fines' => ['Admin', 'Accounts'],
        // Team, roles, business settings
        'manage-team' => ['Admin'],
    ];

    /** The four staff roles (5 Nov). Customer is the /account login, not a staff role. */
    public const STAFF_ROLES = ['Admin', 'Agent', 'Accounts', 'Driver'];

    /** What each role is for, in plain words (team screen, settings). */
    public const ROLE_DESCRIPTIONS = [
        'Admin' => 'Owner / manager. Sees and does everything: assigns leads, approves big discounts and cancellations, settings.',
        'Agent' => 'Sales person. Takes leads and follows them to the end: quote, booking, driver, handover, return, payment. Sees only their own.',
        'Accounts' => 'Finance. Payments, invoices, refunds, deposits, fines and Salik, fuel, suppliers, expenses, reports. Can\'t take leads.',
        'Driver' => 'Drivers, captains and crew. Only their own jobs on the phone. No money amounts.',
    ];

    /**
     * Old role names (before 5 Nov) and the new role they count as, until the role migration
     * has moved everyone. Operations goes to Admin by default (brief); the setting
     * "legacy_operations_role" can say Agent instead (decision H-33).
     */
    public const LEGACY = [
        'Manager' => 'Admin',
        'Sales' => 'Agent',
        'Staff' => 'Agent',
        'Finance' => 'Accounts',
        'Operations' => 'Admin',
    ];

    /** The new role an old or new role name counts as (Super Admin, Customer and the four stay as they are). */
    public static function canonical(string $role): string
    {
        if ($role === 'Operations') {
            $to = (string) \App\Models\Setting::read('legacy_operations_role', 'Admin');

            return in_array($to, ['Admin', 'Agent'], true) ? $to : 'Admin';
        }

        return self::LEGACY[$role] ?? $role;
    }

    /** A person's roles as the new roles (no role at all counts as Agent, the most limited office role). */
    public static function rolesOf(?User $user): \Illuminate\Support\Collection
    {
        if (! $user) {
            return collect();
        }
        $names = $user->getRoleNames();

        return $names->isEmpty() ? collect(['Agent']) : $names->map(fn ($r) => self::canonical((string) $r))->unique()->values();
    }

    /** The given new role names plus every old name that counts as one of them (for database queries). */
    public static function withLegacy(array $roles): array
    {
        $out = $roles;
        foreach (array_keys(self::LEGACY) as $old) {
            if (in_array(self::canonical($old), $roles, true)) {
                $out[] = $old;
            }
        }

        return array_values(array_unique($out));
    }

    /** Plain-English names for the settings page. */
    public const LABELS = [
        'manage-leads' => 'Leads, customers and the pipeline',
        'assign-leads' => 'Give leads to an agent, move a customer to another agent',
        'manage-bookings' => 'Create and edit bookings',
        'operate-fleet' => 'Hand over, receive, mark cars / yachts ready',
        'record-payments' => 'Record money received',
        'manage-finance' => 'Refunds, deposits, edit / reverse money records, approve expenses, accounts and reports',
        'record-expenses' => 'Enter expenses (they wait for Accounts unless Accounts or Admin enters them)',
        'review-documents' => 'Upload, request and approve customer documents',
        'manage-quotations' => 'Create, send and convert quotations',
        'manage-fleet' => 'Cars, yachts, trips, rates, website publishing',
        'manage-pricing' => 'Pricing: special rates, extras and fees, the rate card',
        'adjust-prices' => 'Change a quoted or booked price, give a discount, waive a fee (a reason is always asked)',
        'approve-prices' => 'Approve a discount above the agent\'s limit',
        'delete-records' => 'Archive leads, customers, bookings, quotations',
        'override-availability' => 'Book / hand over a car that is in maintenance, not ready or missing documents',
        'view-history' => 'See the change history of records',
        'manage-accounts' => 'Invite customers and drivers to log in, switch accounts on / off',
        'manage-drivers' => 'Driver profiles and their documents (passport, ID, licence)',
        'review-damage' => 'Review damage reported by drivers',
        'export-data' => 'Export lists to Excel / CSV (logged)',
        'manage-integrations' => 'Integrations: Google Maps, payment gateways, social platforms (keys)',
        'manage-suppliers' => 'Suppliers, their vehicles and cost rates',
        'manage-email' => 'Email templates and group announcements',
        'manage-apps' => 'Installable apps: icons, install links, devices, test notifications',
        'dispatch-drivers' => 'Dispatch board: give and move driver jobs',
        'track-drivers' => 'Live drivers map (only while a job is active, when switched on)',
        'manage-fines' => 'Traffic fines, Salik / Darb / parking, toll accounts, statement imports',
        'manage-team' => 'Team, roles and business settings',
    ];

    /** Office roles that can be given abilities (Super Admin always has everything; Driver works from the phone screens). */
    public const ROLES = ['Admin', 'Agent', 'Accounts'];

    /**
     * Roles allowed an ability, as new role names plus the old names that count as them
     * (so database queries for "who may do this" still find people not yet moved).
     * From Settings › Advanced when an Admin changed it there (role_abilities_v2), else the defaults above.
     * The old matrix (role_abilities, before 5 Nov) is kept in the settings table but no longer read.
     */
    public static function rolesFor(string $ability): array
    {
        $stored = \App\Models\Setting::read('role_abilities_v2');
        $roles = is_array($stored) && array_key_exists($ability, $stored)
            ? array_values(array_intersect((array) $stored[$ability], self::ROLES))
            : (self::ABILITIES[$ability] ?? []);

        // Admin can never lock themselves out of the settings.
        if ($ability === 'manage-team' && ! in_array('Admin', $roles, true)) {
            $roles[] = 'Admin';
        }

        return self::withLegacy($roles);
    }

    public static function register(): void
    {
        foreach (array_keys(self::ABILITIES) as $ability) {
            Gate::define($ability, fn (User $user) => self::rolesOf($user)->intersect(self::rolesFor($ability))->isNotEmpty());
        }
    }

    /** 5 Nov: Admin (or the hidden Super Admin), the role that approves and decides. */
    public static function isAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && ($user->hasRole('Super Admin') || self::rolesOf($user)->contains('Admin'));
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
        return self::agentScopedUser(auth()->user());
    }

    /** Same check for any person (4 Nov: who may be told about a record). */
    public static function agentScopedUser(?\App\Models\User $user): bool
    {
        if (! $user || ! (bool) \App\Models\Setting::read('agent_own_records_only', true)) {
            return false;
        }
        if ($user->hasRole('Super Admin')) {
            return false;
        }
        $roles = self::rolesOf($user)->diff(['Customer', 'Driver']);

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
            // 5 Nov (S3): own leads, plus new leads waiting for any agent to accept them.
            'lead' => $query->where(fn ($q) => $q->where('assigned_to', $id)
                ->orWhere(fn ($w) => $w->whereNull('assigned_to')->whereNotNull('broadcast_at')
                    ->whereNotIn('status', [\App\Enums\LeadStatus::Converted->value, \App\Enums\LeadStatus::Lost->value]))),
            // 5 Nov (S3): a customer with an agent belongs to that agent only; one without comes from my leads / bookings.
            'customer' => $query->where(fn ($q) => $q->where('assigned_to', $id)
                ->orWhere(fn ($n) => $n->whereNull('assigned_to')->where(fn ($m) => $m
                    ->whereIn('id', fn ($s) => $s->select('customer_id')->from('leads')->where('assigned_to', $id)->whereNotNull('customer_id'))
                    ->orWhereIn('id', fn ($s) => $s->select('customer_id')->from('bookings')->where('assigned_to', $id)->whereNotNull('customer_id'))))),
            'booking' => $query->where(fn ($q) => $q->where('assigned_to', $id)->orWhereIn('customer_id', $myCustomers())),
            // 5 Nov (S3): a quotation follows its lead / customer; "made by me" counts while they have no agent.
            'quotation' => $query->where(fn ($q) => $q->whereIn('customer_id', $myCustomers())
                ->orWhereIn('lead_id', fn ($s) => $s->select('id')->from('leads')->where('assigned_to', $id))
                ->orWhere(fn ($m) => $m->where('created_by', $id)
                    ->where(fn ($l) => $l->whereNull('lead_id')->orWhereIn('lead_id', fn ($s) => $s->select('id')->from('leads')->whereNull('assigned_to')))
                    ->where(fn ($c) => $c->whereNull('customer_id')->orWhereIn('customer_id', fn ($s) => $s->select('id')->from('customers')->whereNull('assigned_to'))))),
            'transaction' => $query->where(fn ($q) => $q->where('user_id', $id)
                ->orWhereIn('booking_id', fn ($s) => $s->select('id')->from('bookings')->where('assigned_to', $id))
                ->orWhereIn('customer_id', $myCustomers())),
            'document' => $query->whereIn('customer_id', $myCustomers()),
            default => $query,
        };
    }
}
