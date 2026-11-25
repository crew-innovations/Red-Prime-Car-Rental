<?php

namespace App\Support\Uploads;

use Filament\Forms;
use Filament\Forms\Components\FileUpload;

/**
 * 25 Nov 2026: "Scan with camera" beside document FileUpload fields (meeting: camera-based
 * scan, keep choose-file). Uses public/js/rp-camera.js → FilePond / file input. No paid SaaS.
 */
class CameraUpload
{
    /** Script tags for CRM / account / public layouts. */
    public static function scriptTag(): string
    {
        $js = public_path('js/rp-camera.js');
        $v = is_file($js) ? (string) filemtime($js) : '1';

        return '<script src="'.asset('js/rp-camera.js').'?v='.$v.'" defer></script>';
    }

    /**
     * Mark a FileUpload as a camera target and add a "Scan with camera" button below it.
     * Returns [FileUpload, ViewField] to spread into a schema (or use decorate() on an existing field).
     *
     * @return array{0: FileUpload, 1: Forms\Components\ViewField}
     */
    public static function withScan(FileUpload $upload, ?string $target = null, ?string $hint = null): array
    {
        $name = $upload->getName();
        $key = $target ?: (string) $name;
        self::decorate($upload, $key);

        return [$upload, self::button($key, $hint)];
    }

    /** Apply camera target attributes to an existing FileUpload (when the button is added separately). */
    public static function decorate(FileUpload $upload, ?string $target = null): FileUpload
    {
        $key = $target ?: (string) $upload->getName();
        $attrs = method_exists($upload, 'getExtraAttributes') ? $upload->getExtraAttributes() : [];

        // Do not overwrite an existing helper (e.g. "already on file" on customer docs).
        return $upload->extraAttributes(array_merge($attrs, [
            'data-rp-camera-target' => $key,
        ]));
    }

    public static function button(string $target, ?string $hint = null): Forms\Components\ViewField
    {
        return Forms\Components\ViewField::make('_camera_'.preg_replace('/[^A-Za-z0-9_]/', '_', $target))
            ->label('')
            ->dehydrated(false)
            ->view('filament.forms.camera-scan')
            ->viewData(['target' => $target, 'hint' => $hint]);
    }
}
