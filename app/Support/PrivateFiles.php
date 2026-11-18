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
            if (! \App\Support\Access::agentScopedUser($user)) {
                return true;
            }

            return self::agentAllows($user, $path); // 5 Nov (S3): agents only open files of their own records
        }

        if ($user->isDriverOnly()) {
            if (DriverDocument::where('user_id', $user->id)->where('path', $path)->exists()
                || \App\Models\FuelEntry::where('user_id', $user->id)->where('receipt_path', $path)->exists()) { // 5 Nov (S5): own fuel receipts
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
            return CustomerDocument::where('customer_id', $user->customer_id)->where('path', $path)->exists()
                // 18 Nov: their own rental contract PDFs
                || \App\Models\ContractVersion::where('path', $path)->whereHas('contract', fn ($q) => $q->where('customer_id', $user->customer_id))->exists();
        }

        return false;
    }

    /** An agent: documents of their own customers, photos / papers of their own bookings, fleet files. */
    protected static function agentAllows(User $user, string $path): bool
    {
        // scopeOwn() works for the signed-in person; anyone else is refused.
        if (! auth()->user()?->is($user)) {
            return false;
        }
        $own = fn (string $kind, $query) => \App\Support\Access::scopeOwn($query, $kind);

        $doc = CustomerDocument::where('path', $path)->first();
        if ($doc) {
            return $own('customer', \App\Models\Customer::withTrashed())->whereKey($doc->customer_id)->exists();
        }
        if (DriverDocument::where('path', $path)->exists() || str_starts_with($path, 'fuel/')) {
            return false;
        }
        // 18 Nov: rental contract PDFs of their own bookings
        if ($version = \App\Models\ContractVersion::with('contract')->where('path', $path)->first()) {
            return $own('booking', Booking::withTrashed())->whereKey($version->contract?->booking_id)->exists();
        }
        $media = self::media($path);

        return match (true) {
            ! $media => false,
            $media->model_type === 'booking' => $own('booking', Booking::withTrashed())->whereKey($media->model_id)->exists(),
            $media->model_type === (new DamageReport)->getMorphClass() => ($r = DamageReport::find($media->model_id)) && $r->booking_id
                && $own('booking', Booking::withTrashed())->whereKey($r->booking_id)->exists(),
            in_array($media->model_type, ['car', 'yacht', 'trip'], true) => true,
            $media->model_type === 'rental_contract' => ($c = \App\Models\RentalContract::withTrashed()->find($media->model_id))
                && $own('booking', Booking::withTrashed())->whereKey($c->booking_id)->exists(), // 18 Nov
            default => false,
        };
    }

    /**
     * Paths sent back by an upload field must be fresh uploads in the expected
     * folder, never an existing file of someone else (the browser could send any text).
     */
    public static function freshUploads(array $paths, string $folder, int $minutes = 120): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('private');
        $folder = rtrim($folder, '/').'/';

        return array_values(array_filter($paths, function ($path) use ($disk, $folder, $minutes) {
            return is_string($path)
                && str_starts_with($path, $folder)
                && ! str_contains($path, '..')
                && $disk->exists($path)
                && $disk->lastModified($path) >= time() - $minutes * 60 // the real clock: file times are real too
                && ! \App\Models\CustomerDocument::withTrashed()->where('path', $path)->exists()
                && ! \App\Models\DriverDocument::where('path', $path)->exists();
        }));
    }

    /** Media files live in "<media id>/..." (also conversions/ and responsive-images/ below it). */
    protected static function media(string $path): ?Media
    {
        $id = strtok($path, '/');

        return ctype_digit((string) $id) ? Media::find((int) $id) : null;
    }
}
