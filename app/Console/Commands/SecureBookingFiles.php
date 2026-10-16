<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Booking photos and agreements used to be stored in the public "uploads"
 * folder. This copies them to the private disk (served only through signed
 * links to logged-in staff) and points the records there.
 *
 * Public copies are kept unless --remove-public-copies is given.
 */
class SecureBookingFiles extends Command
{
    protected $signature = 'crm:secure-booking-files {--remove-public-copies : Delete the old public copies after a successful copy}';

    protected $description = 'Move booking photos / agreements from the public uploads folder to private storage';

    public function handle(): int
    {
        $from = Storage::disk('uploads');
        $to = Storage::disk('private');
        $moved = 0;
        $failed = 0;

        Media::query()
            ->where('model_type', (new Booking)->getMorphClass())
            ->where('disk', 'uploads')
            ->orderBy('id')
            ->each(function (Media $media) use ($from, $to, &$moved, &$failed) {
                $dir = trim(dirname($media->getPathRelativeToRoot()), '/.');
                $files = $dir !== '' ? $from->allFiles($dir) : [$media->getPathRelativeToRoot()];

                foreach ($files as $file) {
                    if (! $to->exists($file) && ! $to->writeStream($file, $from->readStream($file))) {
                        $this->error("Could not copy {$file} (media #{$media->id}). Nothing changed for it.");
                        $failed++;

                        return;
                    }
                }

                $media->forceFill(['disk' => 'private', 'conversions_disk' => 'private'])->saveQuietly();
                $moved++;

                if ($this->option('remove-public-copies') && $dir !== '') {
                    $from->deleteDirectory($dir);
                }
            });

        $this->info("Booking files secured: {$moved}. Failed: {$failed}.");
        if ($moved && ! $this->option('remove-public-copies')) {
            $this->line('Old public copies were kept in public/uploads. Remove them later with --remove-public-copies once checked.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
