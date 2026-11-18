<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\UserDevice;
use App\Support\Push\Apps;
use Illuminate\Http\Request;

/**
 * The browser tells the CRM about its device (4 Nov, 16.2): installed or not, notification
 * permission and the push subscription. Only for the signed-in person's own devices.
 */
class DeviceController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 403);
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'permission' => ['nullable', 'in:granted,denied,default'],
            'standalone' => ['nullable', 'boolean'],
            'version' => ['nullable', 'string', 'max:20'],
            'subscription.endpoint' => ['nullable', 'url', 'starts_with:https://', 'max:2000'],
            'subscription.keys.p256dh' => ['nullable', 'string', 'max:200'],
            'subscription.keys.auth' => ['nullable', 'string', 'max:100'],
        ]);

        $device = UserDevice::firstOrNew(['user_id' => $user->id, 'device_id' => $data['device_id']]);
        $isNew = ! $device->exists;
        $ua = (string) $request->userAgent();
        $device->fill([
            'app' => Apps::for($user),
            'user_agent' => mb_substr($ua, 0, 255),
            'label' => self::label($ua),
            'permission' => $data['permission'] ?? $device->permission ?? 'default',
            'app_version' => $data['version'] ?? $device->app_version,
            'last_seen_at' => now(),
            'session_id' => $request->hasSession() ? $request->session()->getId() : $device->session_id,
        ]);
        if (! empty($data['standalone']) && ! $device->installed) {
            $device->installed = true;
            $device->installed_at = now();
            rescue(fn () => AuditLog::event('app', 'App installed on '.$device->label.' ('.UserDevice::APPS[$device->app].')'), report: false);
        }

        $endpoint = $data['subscription']['endpoint'] ?? null;
        $newSubscription = false;
        if ($endpoint && ($data['permission'] ?? null) === 'granted') {
            $hash = hash('sha256', $endpoint);
            if ($device->endpoint_hash !== $hash) {
                // The same browser subscription can't belong to two people / devices.
                UserDevice::where('endpoint_hash', $hash)->where('id', '!=', $device->id ?? 0)->get()->each->clearPush();
                $device->forceFill([
                    'push_endpoint' => $endpoint, 'endpoint_hash' => $hash,
                    'push_keys' => ['p256dh' => $data['subscription']['keys']['p256dh'] ?? null, 'auth' => $data['subscription']['keys']['auth'] ?? null],
                    'push_failures' => 0,
                ]);
                $newSubscription = true;
            }
        } elseif (($data['permission'] ?? null) === 'denied' && $device->endpoint_hash) {
            $device->forceFill(['push_endpoint' => null, 'endpoint_hash' => null, 'push_keys' => null]);
        }
        $device->save();

        if ($newSubscription) {
            rescue(fn () => AuditLog::event('push', 'Notifications allowed on '.$device->label), report: false);
        }

        // 4 Nov (13.5, setting off by default): a customer is told about a sign-in on a new device.
        if ($isNew && $user->isCustomerAccount() && \App\Models\Setting::read('portal_new_device_email', false)
            && UserDevice::where('user_id', $user->id)->where('id', '!=', $device->id)->exists()) {
            rescue(fn () => \App\Support\Notifications\Notifier::send($user, new \App\Notifications\ManualEmail(
                'New sign-in to your account',
                'Hi '.strtok((string) $user->name, ' ').",\n\nYour account was just opened on a new device: {$device->label}, ".now()->format('d M Y, H:i')
                    .".\n\nIf this was you, there is nothing to do. If not, use \"Forgot password\" on the login page to choose a new password, and contact us.",
                'security', 'New device sign-in'), $device->user, 'new_device:'.$device->id));
        }

        return response()->json(['ok' => true, 'new' => $isNew]);
    }

    public static function label(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        return $os.' · '.$browser;
    }
}
