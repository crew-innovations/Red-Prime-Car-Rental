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

        return response()->view('public.contract-sign', ['c' => $contract, 'b' => $contract->booking, 'token' => $token])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
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
        ], ['agree.accepted' => 'Please tick that you have read and agree to the contract.', 'signature.required' => 'Please sign in the box.']);
        $png = DriverWork::signaturePng($data['signature']);
        if (! $png) {
            throw ValidationException::withMessages(['signature' => 'Please sign in the box.']);
        }
        Contracts::sign($contract, $png, $data['name'], 'link');
        rescue(fn () => \App\Support\Drivers\Notify::staff('manage-contracts', 'Contract signed: '.$contract->number, $data['name'].' signed on their phone.',
            \App\Filament\Resources\RentalContractResource::getUrl('view', ['record' => $contract]), $contract->booking?->assignee, 'success', 'contract_signed'), report: false);

        return response()->view('public.contract-sign', ['c' => $contract->fresh(), 'b' => $contract->booking, 'token' => null, 'done' => true])
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
}
