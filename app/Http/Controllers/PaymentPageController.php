<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Support\Payments\GatewayError;
use App\Support\Payments\Payments;
use Illuminate\Http\Request;

/**
 * Customer payment page /pay/{token} (brief 28 Oct-2, Phase 13). Shows what is
 * being paid and the connected methods; the provider's checkout does the rest.
 * Coming back from checkout never marks anything paid by itself: the CRM asks
 * the provider, or waits for its signed webhook.
 */
class PaymentPageController extends Controller
{
    protected function find(string $token): PaymentRequest
    {
        abort_unless(strlen($token) >= 32, 404);

        return PaymentRequest::query()->with(['booking.bookable', 'quotation', 'customer'])->where('token', $token)->firstOrFail();
    }

    public function show(Request $request, string $token)
    {
        $r = $this->find($token);

        return response()->view('public.pay', [
            'r' => $r,
            'choices' => $r->isPayable() ? Payments::choices($r) : [],
            'cancelled' => $request->boolean('cancelled'),
            'failed' => $request->boolean('failed'),
            'returned' => (bool) session('returned'),
        ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function start(Request $request, string $token, string $provider)
    {
        $r = $this->find($token);
        try {
            return redirect()->away(Payments::start($r, $provider));
        } catch (GatewayError $e) {
            return redirect()->route('pay.show', $token)->withErrors(['pay' => $e->getMessage()]);
        }
    }

    public function back(Request $request, string $token, string $provider)
    {
        $r = $this->find($token);
        Payments::returned($r, $provider, $request);

        // Drop the provider's query string from the address bar.
        return redirect()->route('pay.show', $token)->with('returned', true);
    }
}
