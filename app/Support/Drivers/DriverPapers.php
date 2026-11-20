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
        foreach (self::checklist($driver) as $row) {
            $text = match ($row['state']) {
                'missing' => $row['label'].' missing',
                'pending' => $row['label'].' waiting for approval',
                'rejected' => $row['label'].' rejected (send it again)',
                'expired' => $row['label'].' expired',
                default => null,
            };
            if ($text) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * 20 Nov (item 8): each required document (Business settings) and where it stands:
     * missing / pending / rejected / expired / approved. An approved, not expired copy counts even while a
     * newer copy waits for approval.
     *
     * @return array<string, array{label: string, state: string, doc: ?DriverDocument, waiting: ?DriverDocument}>
     */
    public static function checklist(User $driver): array
    {
        $docs = $driver->driverDocuments()->current()->orderByDesc('id')->get()->groupBy('type');
        $out = [];
        foreach ((array) Setting::read('driver_required_documents', []) as $type) {
            $list = $docs->get($type, collect());
            $approved = $list->first(fn (DriverDocument $d) => ($d->status ?? 'approved') === 'approved');
            $latest = $list->first();
            $waiting = $list->first(fn (DriverDocument $d) => $d->status === 'pending');
            $state = match (true) {
                $approved && $approved->expiryState() !== 'expired' => 'approved',
                (bool) $waiting => 'pending',
                $latest?->status === 'rejected' => 'rejected',
                (bool) $approved => 'expired',
                default => 'missing',
            };
            $out[$type] = ['label' => DriverDocument::TYPES[$type] ?? $type, 'state' => $state, 'doc' => $approved ?? $latest, 'waiting' => $waiting];
        }

        return $out;
    }

    /** 20 Nov (item 8): one line for staff assigning a job; null when the driver's file is complete. Never blocks. */
    public static function assignWarning(?User $driver): ?string
    {
        if (! $driver || ! ($p = self::problems($driver))) {
            return null;
        }

        return $driver->name.'\'s documents are not complete: '.implode(', ', $p).'. The job can still be given; please sort them out.';
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
