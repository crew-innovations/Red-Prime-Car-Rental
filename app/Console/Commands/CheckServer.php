<?php

namespace App\Console\Commands;

use App\Support\Notifications\QueueHealth;
use Illuminate\Console\Command;

/**
 * php artisan crm:check-server (4 Nov, decision H-30): what the server can do, for Bilal to run once.
 * Prints no secrets.
 */
class CheckServer extends Command
{
    protected $signature = 'crm:check-server';

    protected $description = 'Show what this server supports (scheduler, queue, push, PDFs, QR codes)';

    public function handle(): int
    {
        $ok = fn (bool $v) => $v ? 'yes' : 'NO';
        $rows = [
            ['PHP version', PHP_VERSION],
            ['proc_open (scheduled commands as separate processes)', $ok(function_exists('proc_open')).(function_exists('proc_open') ? '' : ' (scheduled jobs run in-process instead)')],
            ['openssl (web push, secure links)', $ok(extension_loaded('openssl'))],
            ['gd (QR codes, app icons, PDF photos)', $ok(extension_loaded('gd'))],
            ['mbstring', $ok(extension_loaded('mbstring'))],
            ['gmp / bcmath (not needed by this CRM)', ($ok(extension_loaded('gmp'))).' / '.$ok(extension_loaded('bcmath'))],
            ['PDF library (dompdf)', $ok(class_exists(\Dompdf\Dompdf::class))],
            ['QR code library', $ok(class_exists(\chillerlan\QRCode\QRCode::class))],
            ['Queue connection', config('queue.default')],
            ['Queue worker heartbeat', QueueHealth::lastBeat()?->diffForHumans() ?? 'none yet (add the cron: php artisan schedule:run every minute)'],
            ['Mailer in .env', config('mail.default')],
            ['HTTPS address', str_starts_with((string) config('app.url'), 'https://') ? 'yes' : 'NO ('.config('app.url').')'],
        ];
        $this->table(['Check', 'Result'], $rows);

        return self::SUCCESS;
    }
}
