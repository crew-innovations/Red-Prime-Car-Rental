<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Models\Setting;
use App\Support\Sales\QuotationDecision;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Quotation page the customer opens from WhatsApp / email (secure link, one per
 * quotation). The customer can accept or decline it here (28 Oct, 3.4); the
 * name typed, time, IP address and browser are recorded with the exact offer.
 */
class QuotationPublicController extends Controller
{
    protected function find(string $token): Quotation
    {
        $quotation = Quotation::with(['customer', 'lead', 'bookable', 'tripDate'])->where('public_token', $token)->first();
        abort_unless($quotation && $quotation->sent_at, 404);

        return $quotation;
    }

    public function show(string $token)
    {
        $quotation = $this->find($token);

        if (! auth()->check() && ! $quotation->viewed_at) {
            $quotation->forceFill(['viewed_at' => now()])->saveQuietly();
        }

        return response()->view('public.quotation', [
            'q' => $quotation,
            's' => Setting::values(),
        ])->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    public function accept(Request $request, string $token)
    {
        $quotation = $this->find($token);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'agree' => ['accepted'],
        ], ['agree.accepted' => 'Please tick the box to accept the price and terms.']);

        // Signed in to a customer account: it must be that customer's quotation.
        $user = auth()->user();
        if ($user?->isCustomerAccount() && $quotation->customer_id && (int) $quotation->customer_id !== (int) $user->customer_id) {
            abort(403);
        }

        try {
            $result = QuotationDecision::accept($quotation, $this->via(), $data['name'], $request->ip(), (string) $request->userAgent());
        } catch (ValidationException $e) {
            return redirect()->route('quotations.public', ['token' => $token])->withErrors(['quotation' => collect($e->errors())->flatten()->first()]);
        }

        return redirect()->route('quotations.public', ['token' => $token])
            ->with(['accepted' => true, 'documents_url' => $result['documents_url'], 'missing' => $result['missing']]);
    }

    public function decline(Request $request, string $token)
    {
        $quotation = $this->find($token);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $user = auth()->user();
        if ($user?->isCustomerAccount() && $quotation->customer_id && (int) $quotation->customer_id !== (int) $user->customer_id) {
            abort(403);
        }

        try {
            QuotationDecision::decline($quotation, $this->via(), $data['reason'] ?: 'No reason given');
        } catch (ValidationException $e) {
            return redirect()->route('quotations.public', ['token' => $token])->withErrors(['quotation' => collect($e->errors())->flatten()->first()]);
        }

        return redirect()->route('quotations.public', ['token' => $token])->with('declined', true);
    }

    /** Signed in to their customer account = portal; staff = staff; anyone else with the link = link. */
    protected function via(): string
    {
        $user = auth()->user();

        return match (true) {
            (bool) $user?->isCustomerAccount() => 'portal',
            (bool) $user?->hasCrmRole() => 'staff',
            default => 'link',
        };
    }
}
