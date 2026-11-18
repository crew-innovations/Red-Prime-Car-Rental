<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Models\UserDevice;
use App\Support\Push\Vapid;
use App\Support\Push\WebPush;
use Illuminate\Notifications\Notification;

/**
 * Push to every device where the person allowed notifications (4 Nov, 9.1 / 16.3).
 * Subscriptions the push service reports as gone (404 / 410) are removed.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notifiable->is_active || ! method_exists($notification, 'toWebPush')) {
            return;
        }
        $devices = UserDevice::query()->where('user_id', $notifiable->id)->where('permission', 'granted')->whereNotNull('endpoint_hash')->get();
        if ($devices->isEmpty() || ! ($keys = Vapid::keys())) {
            return;
        }
        $payload = array_filter($notification->toWebPush($notifiable), fn ($v) => $v !== null);
        $urgent = in_array($notification->event ?? null, \App\Support\Notifications\Events::URGENT, true);
        foreach ($devices as $device) {
            try {
                $status = WebPush::send((string) $device->push_endpoint, (string) ($device->push_keys['p256dh'] ?? ''), (string) ($device->push_keys['auth'] ?? ''),
                    $payload, $keys['private'], $keys['public'], Vapid::subject(), 86400, $urgent ? 'high' : 'normal');
                if (in_array($status, [404, 410], true)) {
                    $device->clearPush();
                } elseif ($status >= 400) {
                    $device->increment('push_failures');
                } else {
                    $device->forceFill(['last_push_at' => now(), 'push_failures' => 0])->save();
                }
            } catch (\Throwable $e) {
                $device->increment('push_failures');
                report($e);
            }
        }
    }
}
