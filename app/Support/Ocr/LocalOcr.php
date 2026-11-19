<?php

namespace App\Support\Ocr;

use Filament\Notifications\Notification;

/**
 * 19 Nov: document reading without an outside service. The server hands the uploaded files to the
 * staff member's browser (public/js/rp-ocr.js, Tesseract), which reads the text and writes it into
 * a hidden field; TextParser then finds the details. Used while no AI document reader is set up.
 */
class LocalOcr
{
    public const MAX_FILES = 8;

    public const MAX_BYTES = 6 * 1024 * 1024; // per file

    /**
     * @param  list<array{path: string, mime?: ?string, key?: ?string}>  $files
     * @param  string  $target  state path of the hidden field that gets the text back (e.g. data.ocr_text)
     */
    public static function start($livewire, string $target, array $files): void
    {
        $payload = [];
        $skipped = 0;
        foreach (array_slice($files, 0, self::MAX_FILES) as $f) {
            $path = (string) ($f['path'] ?? '');
            if ($path === '' || ! is_file($path) || filesize($path) > self::MAX_BYTES) {
                $skipped++;

                continue;
            }
            $mime = $f['mime'] ?? null;
            $mime = $mime ?: (function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null);
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
                $skipped++;

                continue;
            }
            $payload[] = ['type' => (string) ($f['key'] ?? ''), 'mime' => $mime, 'data' => 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path))];
        }

        if ($payload === []) {
            Notification::make()->warning()->title('Nothing to read')->body('Upload a photo or PDF of the document first.')->send();

            return;
        }
        $livewire->dispatch('rp-ocr-run', id: $livewire->getId(), target: $target, files: $payload);
        Notification::make()->info()->title('Reading the documents on this computer…')
            ->body('This can take up to a minute the first time. The fields fill in by themselves.'.($skipped ? ' '.$skipped.' file(s) skipped (too big or not a photo / PDF).' : ''))
            ->send();
    }

    /** The JSON written back by the browser → list of [type, text] (kept small). */
    public static function texts(?string $json): array
    {
        $rows = json_decode((string) $json, true);
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)->take(self::MAX_FILES * 3)
            ->filter(fn ($r) => is_array($r) && is_string($r['text'] ?? null) && trim($r['text']) !== '')
            ->map(fn ($r) => ['type' => is_string($r['type'] ?? null) ? mb_substr($r['type'], 0, 40) : '', 'text' => mb_substr($r['text'], 0, 20000)])
            ->values()->all();
    }
}
