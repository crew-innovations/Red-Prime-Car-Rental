<?php

use App\Http\Controllers\Api\V1\BookingRequestController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\PriceEstimateController;
use Illuminate\Support\Facades\Route;

/*
 * Public website API (v1). Read-only catalogue of published Yacht Trips and
 * Trips & Packages, plus website enquiries / booking requests.
 * Contract: docs/API.md
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:api-catalog')->group(function () {
        Route::get('yacht-trips', [CatalogController::class, 'yachtTrips'])->name('yacht-trips.index');
        Route::get('yacht-trips/{slug}', [CatalogController::class, 'yachtTrip'])->name('yacht-trips.show')->where('slug', '[A-Za-z0-9\-]+');
        Route::get('packages', [CatalogController::class, 'packages'])->name('packages.index');
        Route::get('packages/{slug}', [CatalogController::class, 'package'])->name('packages.show')->where('slug', '[A-Za-z0-9\-]+');
        Route::get('package-categories', [CatalogController::class, 'packageCategories'])->name('package-categories');
        Route::get('cars', [CatalogController::class, 'cars'])->name('cars.index');
        Route::get('cars/{slug}', [CatalogController::class, 'car'])->name('cars.show')->where('slug', '[A-Za-z0-9\-]+');
        Route::get('yachts', [CatalogController::class, 'yachts'])->name('yachts.index');
        Route::get('yachts/{slug}', [CatalogController::class, 'yacht'])->name('yachts.show')->where('slug', '[A-Za-z0-9\-]+');
        Route::post('price-estimate', PriceEstimateController::class)->name('price-estimate');
        Route::get('transfer-services', [CatalogController::class, 'transferServices'])->name('transfer-services');
        Route::get('vehicle-categories', [CatalogController::class, 'vehicleCategories'])->name('vehicle-categories');
    });
    // 6 Nov (limousine L1): address suggestions for the website, through the CRM (the server key never reaches the browser).
    Route::get('places', function (\Illuminate\Http\Request $request) {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 120);

        return response()->json(['data' => array_values(\App\Support\Maps\GoogleMaps::search($q))]);
    })->middleware('throttle:api-places')->name('places');
    // 11 Nov (limousine): the address of a pin the customer dropped on the map or of "my location".
    Route::get('places/reverse', function (\Illuminate\Http\Request $request) {
        $v = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return response()->json(['data' => ['address' => \App\Support\Maps\GoogleMaps::reverse((float) $v['lat'], (float) $v['lng'])]]);
    })->middleware('throttle:api-places')->name('places.reverse');
    Route::get('places/locate', function (\Illuminate\Http\Request $request) {
        return response()->json(['data' => \App\Support\Maps\GoogleMaps::locate((string) $request->query('q', ''))]);
    })->middleware('throttle:api-places')->name('places.locate');
    Route::middleware('throttle:api-catalog')->group(function () {
    });

    Route::post('booking-requests', [BookingRequestController::class, 'store'])
        ->middleware('throttle:api-booking-requests')
        ->name('booking-requests.store');

    // General website enquiry / call-back (29 Oct, website M3). Creates a lead, never a booking.
    Route::post('enquiries', [\App\Http\Controllers\Api\V1\EnquiryController::class, 'store'])
        ->middleware('throttle:api-booking-requests')
        ->name('enquiries.store');

    Route::post('chat', [\App\Http\Controllers\Api\V1\ChatController::class, 'store'])
        ->middleware('throttle:api-chat')
        ->name('chat.store');
});

// Payment provider webhooks (28 Oct-2, Phase 13): signature / token checked per provider, idempotent.
Route::post('webhooks/payments/{provider}', function (\Illuminate\Http\Request $request, string $provider) {
    $status = \App\Support\Payments\Payments::webhook($provider, $request);

    return response()->json(['received' => $status === 200], $status);
})->whereIn('provider', array_keys(\App\Support\Payments\Payments::GATEWAYS))->middleware('throttle:240,1')->name('payments.webhook');

// 12 Nov: messages from Instagram / Facebook Messenger / WhatsApp (Meta), signed with the app secret.
Route::get('webhooks/messages/meta', function (\Illuminate\Http\Request $request) {
    [$body, $status] = \App\Support\Chat\Social::challenge($request);

    return response($body, $status)->header('Content-Type', 'text/plain');
})->middleware('throttle:60,1');
Route::post('webhooks/messages/meta', function (\Illuminate\Http\Request $request) {
    [$body, $status] = \App\Support\Chat\Social::receive($request);

    return response()->json($body, $status);
})->middleware('throttle:600,1')->name('messages.webhook');

// Lead webhooks from ad platforms (28 Oct-2, Phase 15): signature checked per platform, each lead once.
Route::get('webhooks/leads/{provider}', function (\Illuminate\Http\Request $request, string $provider) {
    [$body, $status] = \App\Support\Leads\LeadWebhooks::challenge($provider, $request);

    return response($body, $status)->header('Content-Type', 'text/plain');
})->whereIn('provider', array_keys(\App\Support\Leads\LeadWebhooks::SOURCES))->middleware('throttle:60,1');
Route::post('webhooks/leads/{provider}', function (\Illuminate\Http\Request $request, string $provider) {
    [$body, $status] = \App\Support\Leads\LeadWebhooks::receive($provider, $request);

    return response()->json($body, $status);
})->whereIn('provider', array_keys(\App\Support\Leads\LeadWebhooks::SOURCES))->middleware('throttle:240,1')->name('leads.webhook');
