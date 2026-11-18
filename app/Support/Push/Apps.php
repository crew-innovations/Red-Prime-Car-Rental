<?php

namespace App\Support\Push;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The three installable web apps (4 Nov, section 16): the same CRM installed as an app icon
 * (a PWA, not a separate native app). Each has its own name, icon, colours, start page and scope,
 * so the driver, staff and customer apps install as separate icons.
 */
class Apps
{
    public const KEYS = ['driver', 'staff', 'customer'];

    public const SIZES = [192, 512];

    public static function defaults(): array
    {
        return [
            'driver' => ['name' => 'RED PRIME Driver', 'short_name' => 'RP Driver', 'theme' => '#d7141a', 'background' => '#0a0908', 'start' => '/crm/driver/jobs', 'scope' => '/crm/driver/'],
            'staff' => ['name' => 'RED PRIME CRM', 'short_name' => 'RP CRM', 'theme' => '#d7141a', 'background' => '#0a0908', 'start' => '/crm', 'scope' => '/crm/'],
            'customer' => ['name' => 'RED PRIME RENTALS', 'short_name' => 'RED PRIME', 'theme' => '#d7141a', 'background' => '#0a0908', 'start' => '/account', 'scope' => '/account/'],
        ];
    }

    public static function get(string $app): array
    {
        $app = in_array($app, self::KEYS, true) ? $app : 'staff';
        $all = (array) Setting::read('pwa_apps', []);
        $saved = (array) ($all[$app] ?? []);
        $cfg = array_replace(self::defaults()[$app], array_filter($saved, fn ($v) => $v !== null && $v !== ''));
        $cfg['scope'] = self::defaults()[$app]['scope']; // fixed per app
        $cfg['version'] = (int) ($saved['version'] ?? 1);
        $cfg['key'] = $app;

        return $cfg;
    }

    /** Which app a signed-in person uses. */
    public static function for(?User $user): string
    {
        return match (true) {
            ! $user => 'staff',
            $user->isCustomerAccount() && ! $user->hasCrmRole() => 'customer',
            $user->isDriverOnly() => 'driver',
            default => 'staff',
        };
    }

    public static function iconDir(string $app): string
    {
        return 'pwa/'.$app;
    }

    /** Icon URL (generated files when published, else the built-in touch icon). */
    public static function iconUrl(string $app, string $file): string
    {
        $cfg = self::get($app);
        $path = self::iconDir($app).'/'.$file;

        return Storage::disk('uploads')->exists($path)
            ? url('uploads/'.$path).'?v='.$cfg['version']
            : url('images/apple-touch-icon.png');
    }

    public static function manifest(string $app): array
    {
        $c = self::get($app);

        return [
            'id' => $c['scope'],
            'name' => $c['name'],
            'short_name' => $c['short_name'],
            'start_url' => $c['start'].(str_contains($c['start'], '?') ? '&' : '?').'source=pwa',
            'scope' => $c['scope'],
            'display' => 'standalone',
            'orientation' => $app === 'driver' ? 'portrait' : 'any',
            'theme_color' => $c['theme'],
            'background_color' => $c['background'],
            'icons' => [
                ['src' => self::iconUrl($app, 'icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => self::iconUrl($app, 'icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => self::iconUrl($app, 'maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }

    /**
     * "Save & publish app": makes every icon size from one square image (or the logo), and
     * raises the version so installed apps update themselves.
     */
    public static function publish(string $app, array $values, ?string $sourcePath = null): array
    {
        $all = (array) Setting::read('pwa_apps', []);
        $prev = (array) ($all[$app] ?? []);
        $values = array_intersect_key($values, array_flip(['name', 'short_name', 'theme', 'background', 'start', 'icon']));
        $next = array_replace($prev, $values, ['version' => (int) ($prev['version'] ?? 1) + 1]);
        $all[$app] = $next;
        Setting::write(['pwa_apps' => $all]);

        $cfg = self::get($app);
        self::makeIcons($app, $sourcePath ?? ($cfg['icon'] ?? null), $cfg['background']);

        return $cfg;
    }

    public static function makeIcons(string $app, ?string $uploadPath, string $background): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }
        $disk = Storage::disk('uploads');
        $src = null;
        if ($uploadPath && $disk->exists($uploadPath)) {
            $src = @imagecreatefromstring((string) $disk->get($uploadPath));
        }
        $square = (bool) $src; // an uploaded icon fills the square; the logo is placed on the background
        $src ??= @imagecreatefromstring((string) @file_get_contents(public_path('images/logo-mark-light.png')));
        if (! $src) {
            return;
        }
        $disk->makeDirectory(self::iconDir($app));
        [$r, $g, $b] = sscanf(ltrim($background, '#'), '%02x%02x%02x') + [0, 0, 0];

        $make = function (int $size, float $pad) use ($src, $square, $r, $g, $b) {
            $im = imagecreatetruecolor($size, $size);
            imagefill($im, 0, 0, imagecolorallocate($im, (int) $r, (int) $g, (int) $b));
            imagealphablending($im, true);
            $w = imagesx($src);
            $h = imagesy($src);
            $box = (int) round($size * (1 - 2 * $pad));
            $scale = $square ? max($box / $w, $box / $h) : min($box / $w, $box / $h);
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            imagecopyresampled($im, $src, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagepng($im, null, 9);
            imagedestroy($im);

            return (string) ob_get_clean();
        };
        $pad = $square ? 0.0 : 0.12;
        foreach (self::SIZES as $size) {
            $disk->put(self::iconDir($app).'/icon-'.$size.'.png', $make($size, $pad));
        }
        $disk->put(self::iconDir($app).'/maskable-512.png', $make(512, $square ? 0.1 : 0.2)); // safe zone for round masks
        $disk->put(self::iconDir($app).'/apple-touch-180.png', $make(180, $pad));
    }

    /** The install page for an app. */
    public static function installUrl(string $app): string
    {
        return url('/app/'.$app);
    }
}
