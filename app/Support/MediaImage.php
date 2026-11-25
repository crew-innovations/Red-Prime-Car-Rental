<?php

namespace App\Support;

/**
 * Detect image files for CRM thumbs and PDF embeds when Spatie mime_type is empty
 * or stored as application/octet-stream (common after camera / FilePond uploads).
 */
class MediaImage
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public static function isImage(?string $mime, ?string $filename = null, ?string $path = null): bool
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime !== '' && str_starts_with($mime, 'image/')) {
            return true;
        }

        return self::extension($filename ?: $path) !== null;
    }

    /** MIME suitable for a data: URI or Content-Type when the stored mime is missing / generic. */
    public static function mime(?string $mime, ?string $filename = null, ?string $path = null): string
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime !== '' && str_starts_with($mime, 'image/')) {
            return $mime;
        }

        return match (self::extension($filename ?: $path)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            default => $mime !== '' ? $mime : 'application/octet-stream',
        };
    }

    public static function extension(?string $name): ?string
    {
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return in_array($ext, self::EXTENSIONS, true) ? $ext : null;
    }
}
