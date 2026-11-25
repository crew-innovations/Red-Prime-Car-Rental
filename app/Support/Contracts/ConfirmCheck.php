<?php

namespace App\Support\Contracts;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Setting;

/**
 * 18 Nov (meeting item 8): a car booking is confirmed only when the customer's details and
 * documents are complete. Which details are needed is in Contract settings; which documents is
 * in Business settings › Documents (per booking type and per resident / GCC / tourist).
 */
class ConfirmCheck
{
    public const FIELD_LABELS = [
        'name' => 'Full name',
        'phone' => 'Phone',
        'email' => 'Email',
        'nationality' => 'Nationality',
        'residency' => 'Resident / GCC / tourist',
        'date_of_birth' => 'Date of birth',
        'address' => 'Address',
        'id_or_passport' => 'Emirates ID or passport number',
        'id_or_passport_issued' => 'ID / passport issue date',
        'id_or_passport_expiry' => 'ID / passport expiry',
        'licence_number' => 'Driving licence number (self-drive)',
        'licence_place_of_issue' => 'Licence place of issue (self-drive)',
        'licence_issued_on' => 'Driving licence issue date (self-drive)',
        'licence_expires_on' => 'Driving licence expiry (self-drive)',
    ];

    /** Licence fields only matter when the customer drives. */
    protected const SELF_DRIVE_ONLY = ['licence_number', 'licence_place_of_issue', 'licence_issued_on', 'licence_expires_on'];

    public static function enabled(): bool
    {
        return (bool) Setting::read('confirm_requires_details', true);
    }

    /** Does this save confirm the booking (or hand it over without confirming first)? */
    public static function isConfirming(Booking $b): bool
    {
        $holding = [BookingStatus::Confirmed, BookingStatus::InProgress];
        if (! in_array($b->status, $holding, true)) {
            return false;
        }
        if (! $b->exists) {
            return true;
        }
        $was = $b->getOriginal('status');
        $was = $was instanceof BookingStatus ? $was : BookingStatus::tryFrom((string) $was);

        return ! in_array($was, [...$holding, BookingStatus::Completed], true);
    }

    /** What is still missing before this booking can be confirmed (empty = nothing). */
    public static function missing(Booking $b): array
    {
        if ($b->service_type !== ServiceType::Car) {
            return [];
        }
        $customer = $b->customer_id ? Customer::withTrashed()->find($b->customer_id) : null;
        if (! $customer) {
            return ['Customer'];
        }
        $out = [];
        if ($customer->isBlacklisted()) {
            $out[] = 'The customer is blacklisted'.($customer->blacklist_reason ? ' ('.$customer->blacklist_reason.')' : '').': only Admin can confirm';
        }

        $selfDrive = $b->drive_mode !== DriveMode::WithDriver;
        foreach ((array) Setting::read('confirm_required_fields', []) as $field) {
            if (! $selfDrive && in_array($field, self::SELF_DRIVE_ONLY, true)) {
                continue;
            }
            $ok = match ($field) {
                'id_or_passport' => filled($customer->id_number) || filled($customer->passport_number),
                'id_or_passport_issued' => match (true) {
                    filled($customer->id_number) => (bool) $customer->id_issued_on,
                    filled($customer->passport_number) => (bool) $customer->passport_issued_on,
                    default => false,
                },
                'id_or_passport_expiry' => match (true) {
                    filled($customer->id_number) => (bool) $customer->id_expires_on,
                    filled($customer->passport_number) => (bool) $customer->passport_expires_on,
                    default => false,
                },
                default => filled($customer->{$field} ?? null),
            };
            if (! $ok) {
                $out[] = self::FIELD_LABELS[$field] ?? str_replace('_', ' ', $field);
            }
        }

        $until = $b->end_at ?? $b->start_at ?? now();
        if ($selfDrive && $customer->licence_expires_on && $customer->licence_expires_on->lt($until)) {
            $out[] = 'Driving licence expires before the return ('.$customer->licence_expires_on->format('d M Y').')';
        }
        if ($selfDrive && ($min = (int) Setting::read('driver_min_age')) > 0 && ($age = $customer->ageOn($b->start_at)) !== null && $age < $min) {
            $out[] = "Driver is {$age}: the minimum age is {$min}";
        }
        if ($selfDrive && ($years = (int) Setting::read('licence_min_years')) > 0 && $customer->licence_issued_on
            && $customer->licence_issued_on->copy()->addYears($years)->gt($b->start_at ?? now())) {
            $out[] = "Driving licence held less than {$years} year(s)";
        }

        foreach ($b->documentChecklist() as [$label, $state, $ok]) {
            if (! $ok) {
                $out[] = $label.' ('.strtolower($state).')';
            }
        }

        // Additional drivers on the contract need their licence too.
        if ($selfDrive && $b->exists && ($contract = \App\Models\RentalContract::where('booking_id', $b->id)->first())) {
            foreach ($contract->drivers()->where('is_main', false)->get() as $d) {
                if (blank($d->licence_number) || ! $d->licence_expires_on) {
                    $out[] = 'Licence details of additional driver '.$d->name;
                } elseif ($d->licence_expires_on->lt($until)) {
                    $out[] = 'Licence of additional driver '.$d->name.' expires before the return';
                }
            }
        }

        return array_values(array_unique($out));
    }
}
