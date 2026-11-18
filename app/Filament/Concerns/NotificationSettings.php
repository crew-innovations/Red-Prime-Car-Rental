<?php

namespace App\Filament\Concerns;

use App\Models\AuditLog;
use App\Models\UserDevice;
use App\Support\Notifications\Preferences;
use Filament\Notifications\Notification;

/**
 * "My notifications" (4 Nov, brief 9.2): each person switches events and channels on or off,
 * and sets quiet hours (phone notifications wait; urgent job changes still come through).
 * Used by the CRM page and the customer account page.
 */
trait NotificationSettings
{
    /** @var array<string, array<string, bool>> */
    public array $prefs = [];

    public ?string $quietFrom = null;

    public ?string $quietTo = null;

    public function mount(): void
    {
        $user = auth()->user();
        foreach (array_keys(Preferences::eventsFor($user)) as $event) {
            foreach (array_keys(Preferences::channelOptions($user)) as $ch) {
                $this->prefs[$event][$ch] = Preferences::isOn($user, $event, $ch);
            }
        }
        $this->quietFrom = $user->quiet_from ? substr((string) $user->quiet_from, 0, 5) : null;
        $this->quietTo = $user->quiet_to ? substr((string) $user->quiet_to, 0, 5) : null;
    }

    public function events(): array
    {
        return Preferences::eventsFor(auth()->user());
    }

    public function channels(): array
    {
        return Preferences::channelOptions(auth()->user());
    }

    public function urgent(string $event): bool
    {
        return in_array($event, \App\Support\Notifications\Events::URGENT, true);
    }

    public function devices()
    {
        return UserDevice::query()->where('user_id', auth()->id())->orderByDesc('last_seen_at')->get();
    }

    public function save(): void
    {
        $user = auth()->user();
        $this->validate([
            'quietFrom' => ['nullable', 'date_format:H:i', 'required_with:quietTo'],
            'quietTo' => ['nullable', 'date_format:H:i', 'required_with:quietFrom'],
        ], [], ['quietFrom' => 'quiet hours from', 'quietTo' => 'quiet hours until']);

        $events = array_keys(Preferences::eventsFor($user));
        $channels = array_keys(Preferences::channelOptions($user));
        $roleDefault = Preferences::roleChannels($user);
        $own = [];
        foreach ($events as $event) {
            foreach ($channels as $ch) {
                $on = (bool) ($this->prefs[$event][$ch] ?? false);
                if ($on !== in_array($ch, $roleDefault, true)) {
                    $own[$event][$ch] = $on; // only what differs from the default is kept
                }
            }
        }
        $before = (array) ($user->notification_prefs ?? []);
        $user->forceFill([
            'notification_prefs' => $own ?: null,
            'quiet_from' => $this->quietFrom ?: null,
            'quiet_to' => $this->quietTo ?: null,
        ])->save();
        if ($before != $own || $user->wasChanged(['quiet_from', 'quiet_to'])) {
            $off = collect($own)->flatMap(fn ($chs, $ev) => collect($chs)->filter(fn ($v) => ! $v)->keys()->map(fn ($c) => $ev.':'.$c))->values()->all();
            AuditLog::note($user, 'notifications', 'Notification settings changed'.($off ? ' (off: '.implode(', ', array_slice($off, 0, 12)).(count($off) > 12 ? '…' : '').')' : '')
                .($this->quietFrom ? ' · quiet hours '.$this->quietFrom.'–'.$this->quietTo : ''));
        }
        Notification::make()->title('Your notification settings are saved')->success()->send();
    }

    public function removeDevice(int $id): void
    {
        $device = UserDevice::query()->where('user_id', auth()->id())->findOrFail($id);
        $device->delete();
        AuditLog::note(auth()->user(), 'notifications', 'Device removed: '.($device->label ?: 'unknown device'));
        Notification::make()->title('Device removed. It gets no more notifications.')->success()->send();
    }

    public function testPush(): void
    {
        $user = auth()->user();
        $has = UserDevice::query()->where('user_id', $user->id)->whereNotNull('push_endpoint')->exists();
        if (! $has) {
            Notification::make()->title('No device with notifications on yet')->body('Open the app on your phone and tap "Allow notifications".')->warning()->send();

            return;
        }
        $n = new \App\Notifications\CrmMessage('Test notification', 'Notifications work on this device.', null, false, 'heroicon-o-bell', 'success', 'other');
        $n->channels = ['push'];
        \App\Support\Notifications\Notifier::deliverNow($user, $n);
        Notification::make()->title('Test sent to your devices')->success()->send();
    }
}
