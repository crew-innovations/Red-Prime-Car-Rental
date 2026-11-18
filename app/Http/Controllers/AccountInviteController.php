<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Accounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** "Choose your password" page behind an invite link (drivers and customers). */
class AccountInviteController extends Controller
{
    public function show(Request $request, User $user)
    {
        if (! $this->usable($request, $user)) {
            return $this->page('public.invite-closed', [], 410);
        }

        return $this->page('public.invite', ['user' => $user, 'action' => $request->getRequestUri()]);
    }

    public function store(Request $request, User $user)
    {
        if (! $this->usable($request, $user)) {
            return $this->page('public.invite-closed', [], 410);
        }

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'The two passwords are not the same.',
        ]);

        $first = ! $user->password_set_at;
        $user->forceFill([
            'password' => $request->input('password'),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password_set_at' => now(),
        ])->save();
        AuditLog::note($user, 'password', 'Password chosen through the login link');
        // 4 Nov (13.3): a short confirmation, so a password set by someone else is noticed.
        \App\Support\Notifications\Notifier::send($user, new \App\Notifications\ManualEmail(
            $first ? 'Your account is ready' : 'Your password was changed',
            'Hi '.strtok((string) $user->name, ' ').",\n\n".($first ? 'Your password is set. You can log in any time at '.Accounts::homeUrl($user).'.' : 'The password of your account was just changed.')
                ."\n\nIf this was not you, please contact us straight away.",
            'security', $first ? 'Password set' : 'Password changed'));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->to(Accounts::homeUrl($user));
    }

    /** Signature checked by the route; here: same password as when the link was made, account allowed. */
    protected function usable(Request $request, User $user): bool
    {
        return hash_equals(Accounts::fingerprint($user), (string) $request->query('f'))
            && $user->is_active
            && $user->login_enabled;
    }

    protected function page(string $view, array $data, int $status = 200)
    {
        return response()->view($view, $data, $status)->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
