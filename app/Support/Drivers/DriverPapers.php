<?php

namespace App\Support\Drivers;

use App\Models\DriverDocument;
use App\Models\Setting;
use App\Models\User;

/** Is a driver's file complete? Mandatory fields and documents come from Business settings. */
class DriverPapers
{
    /** Short problems list, e.g. ["Passport missing", "Driving licence expired"]. Empty = all good. */
    public static function problems(User $driver): array
    {
        $out = [];
        $profile = $driver->driverProfile;
        foreach ($profile ? $profile->missingFields() : self::requiredFieldLabels() as $label) {
            $out[] = $label.' missing';
        }

        $docs = $driver->driverDocuments()->current()->get()->groupBy('type');
        foreach ((array) Setting::read('driver_required_documents', []) as $type) {
            $label = DriverDocument::TYPES[$type] ?? $type;
            $current = $docs->get($type, collect())->sortByDesc('id')->first();
            if (! $current) {
                $out[] = $label.' missing';
            } elseif ($current->expiryState() === 'expired') {
                $out[] = $label.' expired';
            }
        }

        return $out;
    }

    /** Current documents expiring soon or expired (for warnings), any type. */
    public static function warnings(User $driver): array
    {
        return $driver->driverDocuments()->current()->whereNotNull('expires_on')
            ->where('expires_on', '<=', today()->addDays(DriverDocument::warnDays()))
            ->orderBy('expires_on')->get()
            ->map(fn (DriverDocument $d) => $d->typeLabel().': '.$d->expiryLabel())
            ->all();
    }

    protected static function requiredFieldLabels(): array
    {
        return collect((array) Setting::read('driver_required_fields', []))
            ->map(fn ($f) => \App\Models\DriverProfile::FIELDS[$f] ?? null)->filter()->values()->all();
    }
}
