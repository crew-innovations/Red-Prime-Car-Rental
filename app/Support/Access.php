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
        'manage-leads' => ['Admin', 'Manager', 'Sales', 'Staff'],
        // Create / edit bookings
        'manage-bookings' => ['Admin', 'Manager', 'Sales', 'Operations', 'Staff'],
        // Hand over, receive, mark a car / yacht ready
        'operate-fleet' => ['Admin', 'Manager', 'Operations', 'Staff'],
        // Record money received
        'record-payments' => ['Admin', 'Manager', 'Sales', 'Operations', 'Finance'],
        // Refunds, deposit deductions, editing / deleting money records, expenses
        'manage-finance' => ['Admin', 'Manager', 'Finance'],
        // Upload, request and approve / reject customer documents
        'review-documents' => ['Admin', 'Manager', 'Sales', 'Operations', 'Staff'],
        // Create, send and convert quotations
        'manage-quotations' => ['Admin', 'Manager', 'Sales', 'Staff'],
        // Cars, yachts, trips, rates, publishing, website sections, lead sources
        'manage-fleet' => ['Admin', 'Manager'],
        // Delete leads, customers, bookings
        'delete-records' => ['Admin', 'Manager'],
        // Book or hand over a car / yacht that is in maintenance, unavailable or not ready
        'override-availability' => ['Admin', 'Manager'],
        // See who changed what (record history)
        'view-history' => ['Admin', 'Manager'],
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
        'manage-fleet' => 'Cars, yachts, trips, rates, website publishing, drivers',
        'delete-records' => 'Delete leads, customers, bookings, quotations',
        'override-availability' => 'Book / hand over a car that is in maintenance, not ready or missing documents',
        'view-history' => 'See the change history of records',
        'manage-team' => 'Team, roles and business settings',
    ];

    /** Roles that can be given abilities (Super Admin always has everything). */
    public const ROLES = ['Admin', 'Manager', 'Sales', 'Operations', 'Finance', 'Staff'];

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

    /** Drivers only see bookings. */
    public static function isDriverOnly(): bool
    {
        $roles = auth()->user()?->getRoleNames();

        return $roles !== null && $roles->count() === 1 && $roles->first() === 'Driver';
    }
}
