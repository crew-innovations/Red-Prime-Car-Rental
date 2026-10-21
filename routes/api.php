<?php

use App\Http\Controllers\Api\V1\BookingRequestController;
use App\Http\Controllers\Api\V1\CatalogController;
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
    });

    Route::post('booking-requests', [BookingRequestController::class, 'store'])
        ->middleware('throttle:api-booking-requests')
        ->name('booking-requests.store');
});
