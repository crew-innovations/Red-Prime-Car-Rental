<?php

namespace App\Support\Drivers;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\CrmMessage;
use App\Support\Access;
use Illuminate\Support\Collection;

/** Who hears about what. A failed notification never stops the work itself. */
class Notify
{
    public static function driver(?User $driver, string $title, ?string $body = null, ?string $url = null, string $color = 'info'): void
    {
        if (! $driver || ! $driver->is_active) {
            return;
        }
        self::send(collect([$driver]), new CrmMessage($title, $body, $url, (bool) Setting::read('notify_drivers_by_email'), 'heroicon-o-truck', $color));
    }

    /** Active staff whose role has the ability (plus Super Admins), and optionally a specific person. */
    public static function staff(string $ability, string $title, ?string $body = null, ?string $url = null, ?User $also = null, string $color = 'warning'): void
    {
        $roles = array_values(array_unique([...Access::rolesFor($ability), 'Super Admin']));
        $people = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->get();
        if ($also && $also->is_active && $also->hasCrmRole() && ! $also->isDriverOnly()) {
            $people->push($also);
        }
        $people = $people->unique('id')->reject(fn (User $u) => $u->is(auth()->user()));

        self::send($people, new CrmMessage($title, $body, $url, (bool) Setting::read('notify_staff_by_email'), 'heroicon-o-bell-alert', $color));
    }

    protected static function send(Collection $people, CrmMessage $message): void
    {
        foreach ($people as $person) {
            try {
                $person->notify($message);
            } catch (\Throwable $e) {
                report($e); // e.g. mail not set up: the in-app message may still have been stored
            }
        }
    }
}
