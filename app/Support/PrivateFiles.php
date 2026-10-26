<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\CustomerDocument;
use App\Models\DamageReport;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Who may open a private file (documents, handover / return photos), on top of
 * the signed link. CRM staff: everything, as before. Drivers: their own papers
 * and the photos of their own jobs. Customers: only their own documents.
 */
class PrivateFiles
{
    public static function allows(User $user, string $path): bool
    {
        if (! $user->is_active || ! $user->login_enabled) {
            return false;
        }

        if ($user->hasCrmRole() && ! $user->isDriverOnly()) {
            return true;
        }

        if ($user->isDriverOnly()) {
            if (DriverDocument::where('user_id', $user->id)->where('path', $path)->exists()) {
                return true;
            }
            $media = self::media($path);

            return match (true) {
                ! $media => false,
                $media->model_type === 'booking' => Booking::withTrashed()->whereKey($media->model_id)->where('driver_id', $user->id)->exists(),
                $media->model_type === (new DriverProfile)->getMorphClass() => DriverProfile::whereKey($media->model_id)->where('user_id', $user->id)->exists(),
                $media->model_type === (new DamageReport)->getMorphClass() => DamageReport::whereKey($media->model_id)->where('reported_by', $user->id)->exists(),
                default => false,
            };
        }

        if ($user->isCustomerAccount()) {
            return CustomerDocument::where('customer_id', $user->customer_id)->where('path', $path)->exists();
        }

        return false;
    }

    /** Media files live in "<media id>/..." (also conversions/ and responsive-images/ below it). */
    protected static function media(string $path): ?Media
    {
        $id = strtok($path, '/');

        return ctype_digit((string) $id) ? Media::find((int) $id) : null;
    }
}
