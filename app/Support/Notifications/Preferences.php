<?php

namespace App\Support\Notifications;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Which channels a person gets for an event (4 Nov, 9.2): the defaults for their role
 * (Business settings › Notifications), then their own switches in "My profile", then
 * quiet hours (push and WhatsApp wait; urgent driver job changes go through anyway).
 */
class Preferences
{
    public const ROLE_DEFAULTS = [
        'Customer' => ['database', 'push', 'mail'],
        'Driver' => ['database', 'push'],
        '*' => ['database', 'push'], // staff roles
    ];

    /**
     * 5 Nov (S3, B.8): channels an event uses by default for a role, on top of the role's own
     * defaults. A new lead reaches every agent by bell, phone and email; Admin hears about new and
     * accepted leads the same way. Each person can still switch these off in "My notifications".
     */
    public const EVENT_DEFAULTS = [
        'lead_broadcast' => ['Agent' => ['database', 'push', 'mail']],
        'lead_new' => ['Admin' => ['database', 'push', 'mail'], 'Agent' => ['database', 'push', 'mail']],
        'lead_claimed' => ['Admin' => ['database', 'push', 'mail'], 'Agent' => ['database', 'push']],
        'lead_unaccepted' => ['Admin' => ['database', 'push', 'mail'], 'Agent' => ['database', 'push', 'mail']],
        'cancel_requested' => ['Admin' => ['database', 'push', 'mail']],
        'fuel_to_confirm' => ['Accounts' => ['database', 'push']], // 5 Nov (S5)
        'fuel_prices' => ['Accounts' => ['database', 'mail'], 'Admin' => ['database', 'mail']],
    ];

    /** Events a person can switch in their settings (only the ones that can reach them). */
    public const AUDIENCE_EVENTS = [
        'customer' => ['quote_accepted', 'quote_expiring', 'booking_confirmed', 'advance_reached', 'payment_received', 'balance_due', 'handed_over', 'returned', 'late_return', 'deposit_refunded', 'document_rejected', 'message', 'driver_update', 'fine_charged'],
        'driver' => ['job_changed', 'job_soon', 'document_rejected', 'message', 'other'],
    ];

    /** @return array<string, string> event => label */
    public static function eventsFor(User $user): array
    {
        $keys = match (true) {
            $user->isCustomerAccount() && ! $user->hasCrmRole() => self::AUDIENCE_EVENTS['customer'],
            $user->isDriverOnly() => self::AUDIENCE_EVENTS['driver'],
            default => array_values(array_diff(array_keys(Events::ALL), ['job_soon', 'driver_update'])),
        };
        $customer = $user->isCustomerAccount() && ! $user->hasCrmRole();
        $labels = [];
        foreach ($keys as $k) {
            $labels[$k] = $customer ? (self::CUSTOMER_LABELS[$k] ?? Events::label($k)) : Events::label($k);
        }

        return $labels;
    }

    public const CUSTOMER_LABELS = [
        'quote_accepted' => 'Your quotation acceptance',
        'quote_expiring' => 'A quotation about to expire',
        'booking_confirmed' => 'Booking confirmed',
        'advance_reached' => 'Advance received',
        'payment_received' => 'Payment received and receipt',
        'balance_due' => 'Balance to pay',
        'handed_over' => 'Car delivered / charter started',
        'returned' => 'Car returned / charter finished',
        'late_return' => 'Late return',
        'deposit_refunded' => 'Deposit refunded',
        'document_rejected' => 'A document to send again',
        'message' => 'New message from us',
        'driver_update' => 'Your driver is on the way / running late',
        'fine_charged' => 'A traffic fine or tolls on your rental',
    ];

    /** Channels a person can switch (WhatsApp only when connected). */
    public static function channelOptions(User $user): array
    {
        $out = ['database' => $user->isCustomerAccount() && ! $user->hasCrmRole() ? 'In my account' : 'Bell in the CRM', 'push' => 'Phone notification', 'mail' => 'Email'];

        return $out;
    }

    /** Is this switch on for the person (role default, then their own choice)? */
    public static function isOn(User $user, string $event, string $channel): bool
    {
        $own = (array) ($user->notification_prefs ?? []);

        return (bool) ($own[$event][$channel] ?? $own['*'][$channel] ?? in_array($channel, self::roleChannels($user), true));
    }

    /** Role defaults, from Business settings when saved there. */
    public static function roleChannels(User $user): array
    {
        $stored = (array) Setting::read('notification_channels', []);
        $roles = $user->getRoleNames()->isEmpty() ? ['*'] : \App\Support\Access::rolesOf($user)->all();
        $out = [];
        foreach ($roles as $role) {
            $key = in_array($role, ['Customer', 'Driver'], true) ? $role : '*';
            $out = array_merge($out, (array) ($stored[$role] ?? $stored[$key] ?? self::ROLE_DEFAULTS[$key]));
        }
        // Email switches from before 4 Nov still apply.
        if ($user->isDriverOnly() ? Setting::read('notify_drivers_by_email') : (! $user->isCustomerAccount() && Setting::read('notify_staff_by_email'))) {
            $out[] = 'mail';
        }

        return array_values(array_unique($out));
    }

    /** @param  list<string>  $wanted  the channels this event can use */
    public static function channels(User $user, string $event, array $wanted, ?Carbon $at = null): array
    {
        $own = (array) ($user->notification_prefs ?? []);
        $channels = [];
        $eventDefaults = collect(self::EVENT_DEFAULTS[$event] ?? [])
            ->only(\App\Support\Access::rolesOf($user)->all())->flatten()->unique()->all();
        foreach ($wanted as $ch) {
            $on = in_array($ch, self::roleChannels($user), true) || in_array($ch, $eventDefaults, true);
            $on = $own['*'][$ch] ?? $on;
            $on = $own[$event][$ch] ?? $on;
            if ($on) {
                $channels[] = $ch;
            }
        }
        if (! in_array($event, Events::URGENT, true) && self::quiet($user, $at)) {
            $channels = array_values(array_diff($channels, ['push', 'whatsapp']));
        }

        return array_values($channels);
    }

    public static function quiet(User $user, ?Carbon $at = null): bool
    {
        if (! $user->quiet_from || ! $user->quiet_to) {
            return false;
        }
        $now = ($at ?? now())->format('H:i');

        return $user->quiet_from <= $user->quiet_to
            ? $now >= $user->quiet_from && $now < $user->quiet_to
            : $now >= $user->quiet_from || $now < $user->quiet_to; // over midnight, e.g. 22:00–07:00
    }
}
