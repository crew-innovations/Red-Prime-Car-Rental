<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountInvite;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logins for drivers and customers. Nobody types or sends a password: the
 * person gets a link to choose their own. The link is signed, expires, and
 * stops working once a password is set (it is tied to the current password).
 */
class Accounts
{
    public static function linkHours(): int
    {
        return max(1, (int) Setting::read('invite_link_hours', 72));
    }

    /** Ties a link to the current password: setting a new one makes old links useless. */
    public static function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', $user->getKey().'|'.$user->getAuthPassword(), (string) config('app.key')), 0, 24);
    }

    /** A new "choose your password" link. Also used to help someone who forgot theirs. */
    public static function inviteUrl(User $user): string
    {
        $user->forceFill(['invited_at' => now()])->saveQuietly();
        AuditLog::note($user, 'invite', 'Login link created for '.$user->email);

        // Signed on the path only (like private files), so it also works behind the hosting proxy.
        return url(URL::temporarySignedRoute('account.invite', now()->addHours(self::linkHours()), [
            'user' => $user->getKey(),
            'f' => self::fingerprint($user),
        ], absolute: false));
    }

    public static function emailInvite(User $user, string $url): void
    {
        $user->notify(new AccountInvite($url, self::linkHours(), $user->isCustomerAccount() ? 'customer' : 'driver'));
    }

    /** Create (or return) the login of a customer record. */
    public static function customerAccount(Customer $customer, ?string $email = null): User
    {
        if ($existing = User::where('customer_id', $customer->getKey())->first()) {
            return $existing;
        }

        $email = trim((string) ($email ?: $customer->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Add a valid email address for the customer first.']);
        }
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has a login. Use a different email for the customer account.']);
        }

        $user = User::create([
            'name' => $customer->name,
            'email' => $email,
            'phone' => $customer->phone,
            'password' => Str::password(32), // unknown to everyone until they choose their own
            'is_active' => true,
            'login_enabled' => true,
            'customer_id' => $customer->getKey(),
        ]);
        $user->assignRole('Customer');

        return $user;
    }

    /** WhatsApp message with the link (the staff member sends it from their own phone). */
    public static function whatsappUrl(User $user, string $url): ?string
    {
        $name = Str::of($user->name)->before(' ');
        $company = Setting::read('company_name', 'RED PRIME RENTALS');
        $text = "Hi {$name}, here is your link to set up your {$company} login: {$url} (it works for ".self::linkHours().' hours).';

        return Phone::whatsappUrl($user->isCustomerAccount() ? ($user->customer?->whatsapp ?: $user->phone) : $user->phone, $text);
    }

    public static function homeUrl(User $user): string
    {
        return $user->hasCrmRole() ? url('/crm') : url('/account');
    }
}
