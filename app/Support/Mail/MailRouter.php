<?php

namespace App\Support\Mail;

use App\Models\MailAccount;
use App\Models\Setting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Which mailbox sends what (4 Nov, 14.2). Settings › Email accounts › Routing picks a mailbox
 * per type; a type without one uses the default mailbox; with no connected mailbox at all,
 * the .env mailer is used as before. Only mailboxes that passed the test send.
 */
class MailRouter
{
    public const TYPES = [
        'customer_sales' => 'Customer: quotations, bookings, welcome / login links',
        'customer_accounts' => 'Customer: invoices, receipts, payment links, deposits, fines and tolls',
        'customer_marketing' => 'Customer: announcements (marketing)',
        'driver' => 'Drivers & crew: jobs, app install link, documents',
        'staff' => 'Staff: alerts, approvals, notification emails',
        'security' => 'Password and security emails',
    ];

    /** The mailbox for a type, or null for the .env mailer. */
    public static function accountFor(string $type): ?MailAccount
    {
        $routes = (array) Setting::read('mail_routes', []);
        $id = $routes[$type] ?? null;
        $connected = fn ($q) => $q->where('status', 'connected');

        return ($id ? MailAccount::query()->tap($connected)->find($id) : null)
            ?? MailAccount::query()->tap($connected)->where('is_default', true)->first();
    }

    /** Registers the mailbox as a mailer for this request and returns its name. */
    public static function mailerFor(MailAccount $a): string
    {
        $name = 'account_'.$a->id;
        config(['mail.mailers.'.$name => self::transportConfig($a)]);
        app('mail.manager')->purge($name); // settings may have changed since the last send

        return $name;
    }

    public static function transportConfig(MailAccount $a): array
    {
        // Tests can send through the array transport instead of SMTP.
        if (($t = config('mail.account_transport')) && $t !== 'smtp') {
            return ['transport' => $t];
        }

        return [
            'transport' => 'smtp',
            'scheme' => $a->encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $a->host,
            'port' => (int) $a->port,
            'username' => $a->username,
            'password' => $a->password,
            'timeout' => 20,
            'auto_tls' => $a->encryption !== 'none',
            'require_tls' => $a->encryption === 'tls',
        ];
    }

    /** Sets mailer, from and reply-to on a notification email, and tags it for the email log. */
    public static function apply(MailMessage $mail, string $type, ?int $logId = null): MailMessage
    {
        $account = self::accountFor($type);
        if ($account) {
            $mail->mailer(self::mailerFor($account))->from($account->from_address, $account->from_name);
            if ($account->reply_to) {
                $mail->replyTo($account->reply_to);
            }
        }
        $mail->withSymfonyMessage(function ($message) use ($type, $logId, $account) {
            $headers = $message->getHeaders();
            $headers->addTextHeader('X-RP-Mail-Type', $type);
            if ($account) {
                $headers->addTextHeader('X-RP-Mail-Account', (string) $account->id);
            }
            if ($logId) {
                $headers->addTextHeader('X-RP-Email-Log', (string) $logId);
            }
        });

        return $mail;
    }

    /** Sends a short test email through a mailbox; marks it Connected or Failed. */
    public static function test(MailAccount $a, string $to): bool
    {
        try {
            $mailer = app('mail.manager')->build(self::transportConfig($a));
            $mailer->raw("This is a test from the RED PRIME CRM.\nThe mailbox \"{$a->label}\" can send emails.", function ($m) use ($a, $to) {
                $m->to($to)->from($a->from_address, $a->from_name)->subject('Test email from the CRM: '.$a->label);
                if ($a->reply_to) {
                    $m->replyTo($a->reply_to);
                }
            });
            $a->forceFill(['status' => 'connected', 'last_tested_at' => now(), 'last_error' => null])->saveQuietly();
            \App\Models\AuditLog::note($a, 'integration', 'Test email sent through '.$a->title().' to '.$to);

            return true;
        } catch (\Throwable $e) {
            $a->forceFill(['status' => 'failed', 'last_tested_at' => now(), 'last_error' => mb_substr(self::cleanError($e->getMessage(), $a), 0, 500)])->saveQuietly();
            \App\Models\AuditLog::note($a, 'integration', 'Test email through '.$a->title().' failed');

            return false;
        }
    }

    /** Errors never show the password. */
    public static function cleanError(string $msg, ?MailAccount $a = null): string
    {
        if ($a && filled($a->password)) {
            $msg = str_replace((string) $a->password, '••••', $msg);
        }

        return trim($msg);
    }
}
