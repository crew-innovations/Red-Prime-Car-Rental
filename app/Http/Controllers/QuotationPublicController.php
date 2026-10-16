<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Models\Setting;

/** Read-only quotation page the customer opens from WhatsApp / email; prints to PDF. */
class QuotationPublicController extends Controller
{
    public function show(string $token)
    {
        $quotation = Quotation::with(['customer', 'lead', 'bookable', 'tripDate'])->where('public_token', $token)->first();
        abort_unless($quotation && $quotation->sent_at, 404);

        if (! auth()->check() && ! $quotation->viewed_at) {
            $quotation->forceFill(['viewed_at' => now()])->saveQuietly();
        }

        return response()->view('public.quotation', [
            'q' => $quotation,
            's' => Setting::values(),
        ])->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }
}
