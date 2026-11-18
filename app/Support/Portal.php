<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Automatic customer portal accounts (4 Nov, brief 13). A customer gets a login when a quotation
 * is sent or a booking is made (and, if switched on, when a lead with an email comes in). They get
 * an email with a "choose your password" link: never a password. One person, one account:
 * matched by email (any case), then phone. Staff and driver emails, throw-away addresses and
 * spam never get an account. Created inside the same database transaction as the work; the
 * email goes after the commit (Notifier).
 */
class Portal
{
    public const TRIGGERS = ['lead' => 'portal_on_lead', 'quote' => 'portal_on_quote', 'booking' => 'portal_on_booking', 'website' => null, 'staff' => null];

    /** The prospect / customer profile for a lead: an existing one (email, then phone) or a new prospect. */
    public static function customerForLead(Lead $lead): ?Customer
    {
        if ($lead->customer) {
            return $lead->customer;
        }
        $customer = self::match($lead->email, $lead->phone ?: $lead->whatsapp);
        if (! $customer && ! filled($lead->email) && ! filled($lead->phone) && ! filled($lead->whatsapp)) {
            return null;
        }
        $customer ??= Customer::create([
            'name' => $lead->name,
            'phone' => $lead->phone,
            'whatsapp' => $lead->whatsapp ?: $lead->phone,
            'email' => filled($lead->email) ? mb_strtolower(trim($lead->email)) : null,
            'lead_source_id' => $lead->lead_source_id,
            'assigned_to' => $lead->assigned_to,
            'company_name' => $lead->company_name,
            'type' => $lead->pipeline === \App\Enums\Pipeline::B2b ? \App\Enums\CustomerType::Corporate : \App\Enums\CustomerType::Individual,
            'status' => 'prospect',
        ]);
        $lead->forceFill(['customer_id' => $customer->id])->saveQuietly();
        $lead->setRelation('customer', $customer);

        return $customer;
    }

    /** Same person already on file? Email first (any case), then phone. */
    public static function match(?string $email, ?string $phone): ?Customer
    {
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($email)) : null;
        if ($email && ($c = Customer::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->orderBy('id')->first())) {
            return $c;
        }
        if (Phone::key($phone)) {
            return Customer::query()->where(fn ($q) => Phone::whereMatches($q, ['phone', 'whatsapp'], $phone))->orderBy('id')->first();
        }

        return null;
    }

    /** Why this customer cannot get an account (null = they can). */
    public static function blocker(Customer $c): ?string
    {
        $email = mb_strtolower(trim((string) $c->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Portal: no email';
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if (in_array($domain, array_map('mb_strtolower', (array) Setting::read('disposable_email_domains', [])), true)) {
            return 'Portal: throw-away email address';
        }
        $other = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($other && $other->customer_id !== $c->id) {
            return $other->hasCrmRole() ? 'Portal: email belongs to a staff or driver login' : 'Portal: email used by another customer login';
        }

        return null;
    }

    /**
     * Makes the account when the trigger is switched on. Returns the login (new or existing),
     * or null when there is none (the reason is kept in customers.portal_note).
     */
    public static function ensure(Customer $c, string $trigger, bool $sendInvite = true): ?User
    {
        $setting = self::TRIGGERS[$trigger] ?? null;
        if (! Setting::read('customer_accounts_enabled', true) || ($setting && ! Setting::read($setting, false)) || $c->trashed()) {
            return null;
        }
        if ($existing = User::where('customer_id', $c->id)->first()) {
            return $existing;
        }
        if ($why = self::blocker($c)) {
            if ($c->portal_note !== $why) {
                $c->forceFill(['portal_note' => $why])->saveQuietly();
            }

            return null;
        }

        try {
            $user = Accounts::customerAccount($c);
        } catch (ValidationException) {
            return null;
        }
        $user->forceFill(['account_trigger' => $trigger])->saveQuietly();
        $c->forceFill(['portal_note' => null])->saveQuietly();
        AuditLog::note($c, 'account', 'Customer account made automatically ('.$trigger.')');

        if ($sendInvite) {
            $url = Accounts::inviteUrl($user);
            Accounts::emailInvite($user, $url); // queued; goes after the commit
        }

        return $user;
    }

    /** A quotation was sent: the customer (or a new prospect from the lead) gets an account. */
    public static function onQuoteSent(\App\Models\Quotation $q): ?User
    {
        if (! Setting::read('portal_on_quote', true) || ! Setting::read('customer_accounts_enabled', true)) {
            return null;
        }

        return rescue(fn () => DB::transaction(function () use ($q) {
            $customer = $q->customer ?? ($q->lead ? self::customerForLead($q->lead) : null);
            if (! $customer) {
                return null;
            }
            if (! $q->customer_id) {
                $q->forceFill(['customer_id' => $customer->id])->saveQuietly();
            }

            return self::ensure($customer, 'quote', sendInvite: false); // the quotation email carries the login link
        }), null);
    }

    /** A booking was made (staff, website, API). */
    public static function onBooking(\App\Models\Booking $b): ?User
    {
        $c = $b->customer;
        if (! $c) {
            return null;
        }
        if ($c->status === 'prospect') {
            $c->forceFill(['status' => 'customer'])->saveQuietly(); // a booking makes them a customer
        }
        if ($b->source === 'website') {
            return null; // the website booking email has its own link (WebsiteBooking)
        }

        return rescue(fn () => self::ensure($c, 'booking'), null);
    }

    /** A new lead came in (LeadIntake), when switched on. */
    public static function onLead(Lead $lead): ?User
    {
        if (! Setting::read('portal_on_lead', false) || ! Setting::read('customer_accounts_enabled', true) || ! filled($lead->email)) {
            return null;
        }

        return rescue(function () use ($lead) {
            $c = self::customerForLead($lead);

            return $c ? self::ensure($c, 'lead') : null;
        }, null);
    }

    /** Lost as spam / fake: a prospect's login is switched off. */
    public static function onLost(Lead $lead): void
    {
        if (! preg_match('/spam|fake/i', (string) $lead->lost_reason) || ! ($c = $lead->customer) || $c->status !== 'prospect') {
            return;
        }
        $user = User::where('customer_id', $c->id)->first();
        if ($user && $user->login_enabled) {
            $user->forceFill(['login_enabled' => false])->saveQuietly();
            AuditLog::note($c, 'account', 'Customer login switched off: lead marked lost as spam / fake');
        }
    }

    /** "Resend invite" from the customer page, at most 5 an hour per customer. */
    public static function resend(User $user): string
    {
        $key = 'portal-invite:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Five links were sent in the last hour. Try again later.']);
        }
        RateLimiter::hit($key, 3600);
        $url = Accounts::inviteUrl($user);
        Accounts::emailInvite($user, $url);

        return $url;
    }

    /** Change the login email: the new address gets a link to confirm, the old one is told. */
    public static function changeEmail(User $user, string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }
        if (User::whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has a login.']);
        }
        $old = $user->email;
        DB::transaction(function () use ($user, $email, $old) {
            $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
            if ($user->customer && mb_strtolower((string) $user->customer->email) === mb_strtolower((string) $old)) {
                $user->customer->forceFill(['email' => $email])->saveQuietly();
            }
            AuditLog::note($user->customer ?? $user, 'account', 'Login email changed from '.$old.' to '.$email);
            $url = Accounts::inviteUrl($user);
            \App\Support\Notifications\Notifier::send($user, new \App\Notifications\ManualEmail(
                'Please confirm your new login email',
                "Hi {$user->name},\n\nYour login email for ".Setting::read('company_name', 'RED PRIME RENTALS')." is now this address. To confirm it and choose your password, open this link:\n\n{$url}\n\nIt works for ".Accounts::linkHours().' hours.',
                'security', 'Login email changed'));
            if ($old && filter_var($old, FILTER_VALIDATE_EMAIL)) {
                \App\Support\Notifications\Notifier::mail($old, new \App\Notifications\ManualEmail(
                    'Your login email was changed',
                    "Hi {$user->name},\n\nThe login email of your account was changed to {$email}. If you did not ask for this, please contact us straight away.",
                    'security', 'Login email changed (old address)'));
            }
        });
    }

    /** Status shown on the customer page. */
    public static function status(Customer $c): array
    {
        $u = User::where('customer_id', $c->id)->first();

        return match (true) {
            ! $u => ['label' => $c->portal_note ?: 'No account', 'color' => 'gray'],
            ! $u->login_enabled || ! $u->is_active => ['label' => 'Login switched off', 'color' => 'danger'],
            (bool) $u->last_login_at => ['label' => 'Active · last login '.$u->last_login_at->diffForHumans(), 'color' => 'success'],
            (bool) $u->password_set_at => ['label' => 'Password set, never logged in', 'color' => 'info'],
            default => ['label' => 'Invited '.($u->invited_at?->diffForHumans() ?? '').', never logged in', 'color' => 'warning'],
        };
    }
}
