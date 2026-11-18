<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Support\Maps\GoogleMaps;
use App\Support\Transfers\Tracking;

/** 6 Nov (limousine L3): the customer's live tracking page (signed link, no login). */
class TrackController extends Controller
{
    public function show(Booking $booking)
    {
        abort_unless($booking->service_type?->value === 'transfer', 404);

        return response()->view('public.track', [
            'b' => $booking,
            'data' => Tracking::data($booking),
 'payUrl' => \Illuminate\Support\Facades\URL::temporarySignedRoute('track.pay', now()->addHours(12), ['booking' => $booking->id]),
            'feed' => \Illuminate\Support\Facades\URL::temporarySignedRoute('track.data', now()->addHours(12), ['booking' => $booking->id]),
            'mapsKey' => GoogleMaps::browserKey(),
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'same-origin');
    }

    /** 11 Nov: the customer accepts the confirmed price and goes to the payment page. */
    public function pay(Booking $booking)
    {
        abort_unless($booking->service_type?->value === 'transfer', 404);
        try {
            return redirect()->away(\App\Support\Transfers\RideFlow::payUrl($booking));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->to(Tracking::link($booking))->with('ride_error', collect($e->errors())->flatten()->first());
        }
    }

    public function data(Booking $booking)
    {
        abort_unless($booking->service_type?->value === 'transfer', 404);

        return response()->json(Tracking::data($booking))->header('Cache-Control', 'no-store');
    }
}
