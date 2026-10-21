<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * User avatars drawn locally from initials. Replaces Filament's default,
 * which loads a picture from an outside service (ui-avatars.com) and sends it the user's name.
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = (string) Filament::getNameForDefaultAvatar($record);
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('') ?: '?';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#d7141a"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#fff" '
            .'font-family="Poppins, Arial, sans-serif" font-size="24" font-weight="600">'.e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
