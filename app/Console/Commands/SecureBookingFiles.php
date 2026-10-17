<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Booking photos and agreements used to be stored in a public folder. This
 * copies them to the private disk (served only through signed links to
 * logged-in staff) and points the records there.
 *
 * Public copies (and the old public "booking-uploads" temp folder) are kept
 * unless --remove-public-copies is given.
 */
class SecureBookingFiles extends Command
{
    protected $signature = 'crm:secure-booking-files {--remove-public-copies : Delete the old public copies after a successful copy}';

    protected $description = 'Move booking photos / agreements from the public folder to private storage';

    public function handle(): int
    {
        $to = Storage::disk('private');
        $moved = 0;
        $failed = 0;
        $old = [];

        Media::query()
            ->where('model_type', (new Booking)->getMorphClass())
            ->where('disk', '!=', 'private')
            ->orderBy('id')
            ->each(function (Media $media) use ($to, &$moved, &$failed, &$old) {
                $from = Storage::disk($media->disk);
                $dir = trim(dirname($media->getPathRelativeToRoot()), '/.');
                $files = $dir !== '' ? $from->allFiles($dir) : [$media->getPathRelativeToRoot()];

                foreach ($files as $file) {
                    if (! $to->exists($file) && ! $to->writeStream($file, $from->readStream($file))) {
                        $this->error("Could not copy {$file} (media #{$media->id}). Nothing changed for it.");
                        $failed++;

                        return;
                    }
                }

                $old[] = [$media->disk, $dir];
                $media->forceFill(['disk' => 'private', 'conversions_disk' => 'private'])->saveQuietly();
                $moved++;
            });

        $leftovers = Storage::disk('uploads')->allFiles('booking-uploads');

        if ($this->option('remove-public-copies')) {
            foreach ($old as [$disk, $dir]) {
                if ($dir !== '') {
                    Storage::disk($disk)->deleteDirectory($dir);
                }
            }
            Storage::disk('uploads')->deleteDirectory('booking-uploads');
        }

        $this->info("Booking files secured: {$moved}. Failed: {$failed}.");
        if (! $this->option('remove-public-copies') && ($moved || $leftovers)) {
            $this->line('Old public copies were kept ('.(count($old) + count($leftovers)).' item(s), including '.count($leftovers).' in public/uploads/booking-uploads). Remove them once checked with: php artisan crm:secure-booking-files --remove-public-copies');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
