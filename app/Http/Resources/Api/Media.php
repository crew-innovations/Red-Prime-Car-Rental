<?php

namespace App\Http\Resources\Api;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as MediaModel;

/** Public image URLs for API responses (never private files). */
class Media
{
    public static function image(?MediaModel $media): ?array
    {
        if (! $media || $media->disk === 'private') {
            return null;
        }

        return [
            'url' => $media->getFullUrl(),
            'thumb' => $media->hasGeneratedConversion('thumb') ? $media->getFullUrl('thumb') : $media->getFullUrl(),
            'large' => $media->hasGeneratedConversion('large') ? $media->getFullUrl('large') : $media->getFullUrl(),
            'alt' => $media->getCustomProperty('alt') ?: $media->name,
        ];
    }

    /** @return array<int, array> */
    public static function collection(HasMedia $model, string $collection): array
    {
        return $model->getMedia($collection)->map(fn (MediaModel $m) => self::image($m))->filter()->values()->all();
    }

    /** A file stored on the public uploads disk by path (itinerary photos). */
    public static function path(?string $path): ?string
    {
        return $path ? \Illuminate\Support\Facades\Storage::disk('uploads')->url($path) : null;
    }
}
