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

        $user->forceFill([
            'password' => $request->input('password'),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
        AuditLog::note($user, 'password', 'Password chosen through the login link');

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
