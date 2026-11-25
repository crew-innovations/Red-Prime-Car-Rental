<?php

namespace App\Http\Controllers;

use App\Models\RentalContract;
use App\Support\Contracts\Contracts;
use App\Support\Drivers\DriverWork;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 18 Nov: the customer reads and signs the rental contract on their phone, from a link the team
 * sends (no login). The link is single-use and expires (Contract settings).
 */
class ContractSignController extends Controller
{
    public function show(string $token)
    {
        $contract = $this->find($token);
        if (! $contract) {
            return response()->view('public.contract-sign-closed', [], 410)->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response()->view('public.contract-sign', ['c' => $contract, 'b' => $contract->booking, 'token' => $token, 'who' => 'hirer'])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    public function showDriver(string $token)
    {
        $contract = $this->findDriver($token);
        if (! $contract) {
            return response()->view('public.contract-sign-closed', [], 410)->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response()->view('public.contract-sign', ['c' => $contract, 'b' => $contract->booking, 'token' => $token, 'who' => 'driver'])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    /** 19 Nov (suggestion 10): with "code before signing" on, a 6-digit code goes to the customer's email. */
    public static function needsCode(RentalContract $c): bool
    {
        return \App\Models\Setting::read('contract_sign_otp', 'off') === 'email' && filled($c->customer?->email);
    }

    public function code(string $token)
    {
        $contract = $this->find($token);
        if (! $contract || ! self::needsCode($contract)) {
            return response()->view('public.contract-sign-closed', [], 410);
        }
        $code = (string) random_int(100000, 999999);
        $contract->forceFill(['sign_otp_hash' => \Illuminate\Support\Facades\Hash::make($code), 'sign_otp_expires_at' => now()->addMinutes(10), 'sign_otp_attempts' => 0])->saveQuietly();
        rescue(fn () => \App\Support\Notifications\Notifier::mail($contract->customer->email, new \App\Notifications\ContractSignCode($contract, $code), $contract->booking));

        return redirect()->route('contracts.sign', $token)->with('code_sent', true);
    }

    public function store(Request $request, string $token)
    {
        $contract = $this->find($token);
        if (! $contract) {
            return response()->view('public.contract-sign-closed', [], 410);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string'],
            'agree' => ['accepted'],
            'code' => [self::needsCode($contract) ? 'required' : 'nullable', 'digits:6'],
        ], ['agree.accepted' => 'Please tick that you have read and agree to the contract.', 'signature.required' => 'Please sign in the box.', 'code.required' => 'Type the code we emailed you.']);
        if (self::needsCode($contract)) {
            if (! $contract->sign_otp_hash || ! $contract->sign_otp_expires_at?->isFuture() || $contract->sign_otp_attempts >= 5) {
                throw ValidationException::withMessages(['code' => 'The code expired. Ask for a new one.']);
            }
            if (! \Illuminate\Support\Facades\Hash::check($data['code'], $contract->sign_otp_hash)) {
                $contract->increment('sign_otp_attempts');
                throw ValidationException::withMessages(['code' => 'That code is not right.']);
            }
        }
        $png = DriverWork::signaturePng($data['signature']);
        if (! $png) {
            throw ValidationException::withMessages(['signature' => 'Please sign in the box.']);
        }
        Contracts::sign($contract, $png, $data['name'], self::needsCode($contract) ? 'link + email code' : 'link');
        $contract->forceFill(['sign_otp_hash' => null, 'sign_otp_expires_at' => null])->saveQuietly();
        rescue(fn () => \App\Support\Drivers\Notify::staff('manage-contracts', 'Contract signed: '.$contract->number, $data['name'].' signed on their phone.',
            \App\Filament\Resources\RentalContractResource::getUrl('view', ['record' => $contract]), $contract->booking?->assignee, 'success', 'contract_signed'), report: false);

        return response()->view('public.contract-sign', ['c' => $contract->fresh(), 'b' => $contract->booking, 'token' => null, 'who' => 'hirer', 'done' => true])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function storeDriver(Request $request, string $token)
    {
        $contract = $this->findDriver($token);
        if (! $contract) {
            return response()->view('public.contract-sign-closed', [], 410);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string'],
            'agree' => ['accepted'],
        ], ['agree.accepted' => 'Please tick that you have read and agree to the contract.', 'signature.required' => 'Please sign in the box.']);
        $png = DriverWork::signaturePng($data['signature']);
        if (! $png) {
            throw ValidationException::withMessages(['signature' => 'Please sign in the box.']);
        }
        Contracts::signDriver($contract, $png, $data['name'], 'link');
        rescue(fn () => \App\Support\Drivers\Notify::staff('manage-contracts', 'Driver signed: '.$contract->number, $data['name'].' signed the contract on their phone.',
            \App\Filament\Resources\RentalContractResource::getUrl('view', ['record' => $contract]), $contract->booking?->assignee, 'success', 'contract_signed'), report: false);

        return response()->view('public.contract-sign', ['c' => $contract->fresh(), 'b' => $contract->booking, 'token' => null, 'who' => 'driver', 'done' => true])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    protected function find(string $token): ?RentalContract
    {
        if (strlen($token) < 40) {
            return null;
        }
        $c = RentalContract::where('sign_token', $token)->first();

        return $c && $c->sign_token_expires_at?->isFuture() && ! $c->signed_at && in_array($c->status, ['draft', 'open'], true) ? $c : null;
    }

    protected function findDriver(string $token): ?RentalContract
    {
        if (strlen($token) < 40) {
            return null;
        }
        $c = RentalContract::where('driver_sign_token', $token)->first();

        return $c && $c->driver_sign_token_expires_at?->isFuture() && ! $c->driver_signed_at && in_array($c->status, ['draft', 'open'], true) ? $c : null;
    }
}
