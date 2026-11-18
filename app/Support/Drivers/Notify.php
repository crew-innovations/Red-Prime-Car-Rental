<?php

namespace App\Support\Drivers;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CrmMessage;
use App\Support\Access;
use App\Support\Notifications\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who hears about what. Since 4 Nov (brief 9) every notice has an event key, goes through
 * App\Support\Notifications\Notifier (bell, push, email by each person's preferences, queued,
 * one per event and person) and never stops the work itself.
 */
class Notify
{
    public static function driver(?User $driver, string $title, ?string $body = null, ?string $url = null, string $color = 'info', string $event = 'job_changed', ?string $dedupe = null): void
    {
        if (! $driver || ! $driver->is_active) {
            return;
        }
        self::send(collect([$driver]), new CrmMessage($title, $body, $url, false, 'heroicon-o-truck', $color, $event), $dedupe);
    }

    /** One staff member (an agent about their lead or quotation). */
    public static function person(?User $user, string $title, ?string $body = null, ?string $url = null, string $color = 'info', string $event = 'other', ?string $dedupe = null): void
    {
        if (! $user || ! $user->is_active || $user->is(auth()->user())) {
            return;
        }
        self::send(collect([$user]), new CrmMessage($title, $body, $url, false, 'heroicon-o-bell-alert', $color, $event), $dedupe);
    }

    /** Active staff whose role has the ability (plus Super Admins), and optionally a specific person. */
    public static function staff(string $ability, string $title, ?string $body = null, ?string $url = null, ?User $also = null, string $color = 'warning', string $event = 'other', ?string $dedupe = null, array $actions = []): void
    {
        $people = self::withAbility($ability);
        if ($also && $also->is_active && $also->hasCrmRole() && ! $also->isDriverOnly()) {
            $people->push($also);
        }
        $people = $people->unique('id')->reject(fn (User $u) => $u->is(auth()->user()));

        $message = new CrmMessage($title, $body, $url, false, 'heroicon-o-bell-alert', $color, $event);
        $message->quickActions = $actions; // 5 Nov (S3): e.g. "Approve" right in the bell
        self::send($people, $message, $dedupe);
    }

    /**
     * Staff about one record (4 Nov, 9.4): people with the ability who see everything, plus the
     * owner. Agents who only see their own work are told only about their own records.
     */
    public static function team(string $ability, string $title, ?string $body, ?string $url, ?User $owner, string $color, string $event, ?string $dedupe = null): void
    {
        $people = self::withAbility($ability)
            ->reject(fn (User $u) => Access::agentScopedUser($u) && (! $owner || $u->id !== $owner->id));
        if ($owner && $owner->is_active && $owner->hasCrmRole() && ! $owner->isDriverOnly()) {
            $people->push($owner);
        }
        $people = $people->unique('id')->reject(fn (User $u) => $u->is(auth()->user()));

        self::send($people, new CrmMessage($title, $body, $url, false, 'heroicon-o-bell-alert', $color, $event), $dedupe);
    }

    /**
     * The customer (4 Nov, 9.3): in their account (bell, push, email by their settings), or by
     * email only when they have no login yet. Customers only ever hear about their own records.
     */
    public static function customer(?Customer $customer, string $title, ?string $body = null, ?string $url = null, string $event = 'other', ?Model $related = null, ?string $dedupe = null, string $color = 'info'): void
    {
        if (! $customer) {
            return;
        }
        $user = User::query()->where('customer_id', $customer->id)->where('is_active', true)->first();
        $note = new CrmMessage($title, $body, $url ?? ($user ? url('/account') : null), false, 'heroicon-o-bell', $color, $event);
        if ($user && $user->login_enabled) {
            Notifier::send($user, $note, $related ?? $customer, $dedupe);
        } elseif (filled($customer->email) && filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            Notifier::mail($customer->email, $note, $related ?? $customer, $dedupe);
        }
    }

    protected static function withAbility(string $ability): Collection
    {
        $roles = array_values(array_unique([...Access::rolesFor($ability), 'Super Admin']));

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->get();
    }

    protected static function send(Collection $people, CrmMessage $message, ?string $dedupe = null): void
    {
        Notifier::send($people, $message, null, $dedupe);
    }
}
