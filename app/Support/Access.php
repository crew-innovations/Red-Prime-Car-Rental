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
    /** ability => roles allowed */
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

    public static function register(): void
    {
        foreach (self::ABILITIES as $ability => $roles) {
            Gate::define($ability, function (User $user) use ($roles) {
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
