<?php

use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\QuotationPublicController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// Private files (customer and driver documents, handover / return photos): a valid
// signed link AND a logged-in user allowed to see that file (App\Support\PrivateFiles).
Route::get('/crm/private-file/{path}', function (Request $request, string $path) {
    $user = $request->user();
    abort_if(str_contains($path, '..'), 404);
    abort_unless($user && \App\Support\PrivateFiles::allows($user, $path), 403);

    $disk = Storage::disk('private');
    abort_unless($disk->exists($path), 404);

    return $disk->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
})->where('path', '.*')->middleware('signed:relative')->name('private-file');

// Staff-only preview of a Yacht Trip / Package as the website receives it (published or not).
Route::get('/crm/preview/{type}/{id}', function (Request $request, string $type, int $id) {
    abort_unless($request->user()?->is_active, 403);
    abort_if(\App\Support\Access::isDriverOnly(), 403);

    if ($type === 'package') {
        $item = \App\Models\Package::withTrashed()->with(['media', 'category', 'upcomingDepartures'])->findOrFail($id);
        $data = (new \App\Http\Resources\Api\PackageResource($item))->additional(['detail' => true])->toArray($request);
        $sectionOn = \App\Models\Category::isEnabled(\App\Models\Package::CATEGORY);
        $active = $item->status === \App\Enums\TripStatus::Active;
    } elseif ($type === 'yacht-trip') {
        $item = \App\Models\Trip::withTrashed()->with(['media', 'upcomingDates'])->findOrFail($id);
        $data = (new \App\Http\Resources\Api\YachtTripResource($item))->additional(['detail' => true])->toArray($request);
        $sectionOn = \App\Models\Category::isEnabled(\App\Models\Category::YACHT_TRIPS);
        $active = $item->status === \App\Enums\TripStatus::Active;
    } else {
        abort(404);
    }

    $why = collect([
        ! $item->is_published ? '"Show on website" is off' : null,
        ! $active ? 'status is not Active' : null,
        ! $sectionOn ? 'the whole section is switched off' : null,
        $item->trashed() ? 'it is deleted' : null,
    ])->filter()->implode(', ');

    return view('public.preview', ['p' => $data, 'live' => $why === '', 'why' => $why.'.']);
})->whereIn('type', ['package', 'yacht-trip'])->whereNumber('id')->name('crm.preview');

// Customer-facing pages (no login): secure document upload link and quotation view.
Route::get('/upload/{token}', [DocumentUploadController::class, 'show'])->middleware('throttle:60,1')->name('documents.upload');
Route::post('/upload/{token}', [DocumentUploadController::class, 'store'])->middleware('throttle:20,1')->name('documents.upload.store');
Route::get('/quote/{token}', [QuotationPublicController::class, 'show'])->middleware('throttle:60,1')->name('quotations.public');

// "Choose your password" link for driver and customer logins (signed, expires, single use).
Route::get('/invite/{user}', [\App\Http\Controllers\AccountInviteController::class, 'show'])->middleware(['signed:relative', 'throttle:30,1'])->whereNumber('user')->name('account.invite');
Route::post('/invite/{user}', [\App\Http\Controllers\AccountInviteController::class, 'store'])->middleware(['signed:relative', 'throttle:10,1'])->whereNumber('user')->name('account.invite.store');
