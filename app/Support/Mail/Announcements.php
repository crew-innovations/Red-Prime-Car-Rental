<?php

namespace App\Support\Mail;

use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\MailAccount;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ManualEmail;
use App\Support\Access;
use App\Support\Notifications\Notifier;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Group emails (4 Nov, brief 14.3): all drivers, all staff, one role, or customers who agreed to
 * announcements. Every email gets its own row in the email log ("Waiting" until its turn), so
 * the hourly limit per mailbox is kept even without a queue worker: crm:send-announcements
 * (every minute) or "Send the next part now" sends the rows that are due.
 */
class Announcements
{
    public const AUDIENCES = [
        'drivers' => 'All drivers & crew',
        'staff' => 'All staff',
        'role' => 'Staff with one role',
        'customers' => 'Customers who agreed to announcements',
    ];

    /** What sends per run of the scheduler / per click, so one run never takes long. */
    public const PER_RUN = 25;

    public static function type(string $audience): string
    {
        return match ($audience) {
            'drivers' => 'driver',
            'customers' => 'customer_marketing',
            default => 'staff',
        };
    }

    /**
     * @return Collection<int, array{email: string, name: ?string, user: ?User, customer: ?Customer, lang: string}>
     */
    public static function recipients(array $f): Collection
    {
        $audience = (string) ($f['audience'] ?? '');
        $people = match ($audience) {
            'drivers' => self::users(['Driver']),
            'staff' => self::users(array_merge(['Super Admin'], Access::withLegacy(Access::ROLES))),
            'role' => filled($f['role'] ?? null) ? self::users([(string) $f['role']]) : collect(),
            'customers' => self::customers($f),
            default => collect(),
        };

        return $people->filter(fn ($p) => filter_var($p['email'], FILTER_VALIDATE_EMAIL))
            ->unique(fn ($p) => mb_strtolower($p['email']))
            ->values();
    }

    protected static function users(array $roles): Collection
    {
        return User::query()->where('is_active', true)->whereNotNull('email')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->orderBy('name')->get()
            ->map(fn (User $u) => ['email' => trim((string) $u->email), 'name' => $u->name, 'user' => $u, 'customer' => null, 'lang' => 'en']);
    }

    protected static function customers(array $f): Collection
    {
        $q = Customer::query()->acceptsMarketing();
        if (! ($f['include_prospects'] ?? false)) {
            $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'prospect'));
        }
        if ($f['vip_only'] ?? false) {
            $q->where('is_vip', true);
        }
        if (filled($f['customer_type'] ?? null)) {
            $q->where('type', $f['customer_type']);
        }
        if (filled($f['language'] ?? null)) {
            $f['language'] === 'ar'
                ? $q->whereIn('language', ['ar', 'AR', 'Arabic', 'arabic'])
                : $q->where(fn ($w) => $w->whereNull('language')->orWhereNotIn('language', ['ar', 'AR', 'Arabic', 'arabic']));
        }
        if (filled($f['booked_within_months'] ?? null)) {
            $q->whereHas('bookings', fn ($b) => $b->where('start_at', '>=', now()->subMonths((int) $f['booked_within_months'])));
        }

        return $q->orderBy('name')->get()->map(fn (Customer $c) => [
            'email' => trim((string) $c->email), 'name' => $c->name, 'user' => null, 'customer' => $c,
            'lang' => in_array(strtolower((string) $c->language), ['ar', 'arabic'], true) ? 'ar' : 'en',
        ]);
    }

    /** Emails per hour for the mailbox this type goes out from. */
    public static function capacity(?MailAccount $account): int
    {
        return max(1, (int) ($account?->per_hour ?: Setting::read('announcement_per_hour', 100)));
    }

    /** Announcement emails this mailbox sent in the last hour, plus the ones already waiting for it. */
    protected static function used(?MailAccount $account): array
    {
        $base = fn () => EmailLog::query()->whereNotNull('batch')
            ->when($account, fn ($q) => $q->where('mail_account_id', $account->id), fn ($q) => $q->whereNull('mail_account_id'));

        return [
            (int) $base()->whereIn('status', ['queued', 'sending', 'sent', 'failed'])->where('updated_at', '>=', now()->subHour())->count(),
            (int) $base()->where('status', 'held')->count(),
        ];
    }

    /**
     * Puts one announcement in line: a "Waiting" row per person, each with its own text
     * (placeholders filled, Arabic where the template has it and the customer reads Arabic).
     *
     * @return array{batch: string, count: int, hours: int, account: ?string}
     */
    public static function queue(array $f, ?EmailTemplate $template, string $subject, string $body, string $lang = 'en'): array
    {
        $people = self::recipients($f);
        $type = self::type((string) $f['audience']);
        $account = MailRouter::accountFor($type);
        $cap = self::capacity($account);
        [$sentLastHour, $waiting] = self::used($account);
        $batch = 'A'.now()->format('ymdHi').Str::lower(Str::random(4));

        foreach ($people->values() as $i => $p) {
            $l = $lang === 'auto' ? $p['lang'] : $lang;
            $record = $p['customer'] ?? $p['user'];
            if ($template) {
                $text = Templates::render($template, $record, $l);
            } else {
                $values = Templates::values($record, $l);
                $text = ['subject' => strtr($subject, $values), 'body' => strtr($body, $values)];
            }
            $position = $sentLastHour + $waiting + $i;
            EmailLog::create([
                'to_email' => mb_substr($p['email'], 0, 190),
                'to_name' => $p['name'] ? mb_substr($p['name'], 0, 190) : null,
                'type' => $type,
                'kind' => 'Announcement',
                'status' => 'held',
                'subject' => mb_substr($text['subject'], 0, 255),
                'mail_account_id' => $account?->id,
                'related_type' => $record?->getMorphClass(),
                'related_id' => $record?->getKey(),
                'customer_id' => $p['customer']?->id ?? $p['user']?->customer_id,
                'user_id' => $p['user']?->id,
                'triggered_by' => auth()->id(),
                'batch' => $batch,
                'send_after' => now()->addHours(intdiv($position, $cap)),
                'payload' => ['subject' => $text['subject'], 'body' => $text['body'], 'rtl' => $l === 'ar' && (! $template || filled($template->body_ar))],
            ]);
        }

        $last = $sentLastHour + $waiting + max(0, $people->count() - 1);

        return ['batch' => $batch, 'count' => $people->count(), 'hours' => $people->isEmpty() ? 0 : intdiv($last, $cap), 'account' => $account?->label];
    }

    /** Sends the rows whose turn has come, within each mailbox's hourly limit. Returns how many went out. */
    public static function sendDue(int $max = self::PER_RUN, ?string $batch = null): int
    {
        $sent = 0;
        $due = EmailLog::query()->where('status', 'held')->whereNotNull('batch')
            ->when($batch, fn ($q) => $q->where('batch', $batch), fn ($q) => $q->where('send_after', '<=', now()))
            ->orderBy('send_after')->orderBy('id')->limit($max * 4)->get();

        foreach ($due->groupBy(fn ($r) => (string) $r->mail_account_id) as $accountId => $rows) {
            $account = $accountId !== '' ? MailAccount::find((int) $accountId) : null;
            [$used] = self::used($account);
            $room = max(0, self::capacity($account) - $used);
            foreach ($rows->take(min($room, $max - $sent)) as $row) {
                $sent += self::deliver($row) ? 1 : 0;
            }
            if ($sent >= $max) {
                break;
            }
        }

        return $sent;
    }

    /** One waiting row: checked again (unsubscribed? account switched off?) and sent. */
    public static function deliver(EmailLog $row): bool
    {
        $claimed = EmailLog::whereKey($row->id)->where('status', 'held')->update(['status' => 'queued', 'updated_at' => now()]);
        if (! $claimed) {
            return false; // sent by another run
        }
        $customer = $row->type === 'customer_marketing' ? Customer::find($row->customer_id) : null;
        $user = $row->user_id ? User::find($row->user_id) : null;
        $skip = match (true) {
            $row->type === 'customer_marketing' && (! $customer || $customer->unsubscribed_at || ! $customer->marketing_consent_at) => 'Unsubscribed before it was sent',
            $row->user_id && (! $user || ! $user->is_active) => 'Account switched off before it was sent',
            default => null,
        };
        if ($skip) {
            $row->forceFill(['status' => 'cancelled', 'error' => $skip, 'payload' => null])->save();

            return false;
        }
        $p = (array) $row->payload;
        $mail = new ManualEmail((string) ($p['subject'] ?? $row->subject), (string) ($p['body'] ?? ''), $row->type, 'Announcement',
            [], $customer ? self::unsubscribeUrl($customer) : null, (bool) ($p['rtl'] ?? false));
        $mail->emailLogId = $row->id;
        $to = $user ?? (new AnonymousNotifiable)->route('mail', [$row->to_email => $row->to_name]);
        Notifier::dispatch($to, $mail);

        return true;
    }

    /** A link that works without logging in and does not expire (brief 14.3). */
    public static function unsubscribeUrl(Customer $c): string
    {
        return url(URL::signedRoute('email.unsubscribe', ['customer' => $c->id], absolute: false));
    }

    /** Stops what has not gone out yet. */
    public static function cancel(string $batch): int
    {
        return EmailLog::query()->where('batch', $batch)->where('status', 'held')
            ->update(['status' => 'cancelled', 'error' => 'Stopped by '.(auth()->user()?->name ?? 'staff'), 'payload' => null, 'updated_at' => now()]);
    }

    /** Recent announcements with how far they got. */
    public static function batches(int $limit = 10): Collection
    {
        return EmailLog::query()->whereNotNull('batch')
            ->selectRaw("batch, MIN(subject) as subject, MIN(type) as type, MIN(created_at) as created_at, MIN(triggered_by) as triggered_by, COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'held' THEN 1 ELSE 0 END) as waiting, SUM(CASE WHEN status IN ('queued', 'sending') THEN 1 ELSE 0 END) as sending,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled, MAX(send_after) as last_send_after")
            ->groupBy('batch')->orderByDesc('created_at')->limit($limit)->get();
    }
}
