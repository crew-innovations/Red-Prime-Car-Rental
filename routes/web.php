<?php

use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\QuotationPublicController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// Private files (customer documents, handover / return photos): a valid signed
// link AND a logged-in, active staff user are both required.
Route::get('/crm/private-file/{path}', function (Request $request, string $path) {
    $user = $request->user();
    abort_unless($user && $user->is_active, 403);
    abort_if(str_contains($path, '..'), 404);

    $disk = Storage::disk('private');
    abort_unless($disk->exists($path), 404);

    return $disk->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
})->where('path', '.*')->middleware('signed:relative')->name('private-file');

// Customer-facing pages (no login): secure document upload link and quotation view.
Route::get('/upload/{token}', [DocumentUploadController::class, 'show'])->middleware('throttle:60,1')->name('documents.upload');
Route::post('/upload/{token}', [DocumentUploadController::class, 'store'])->middleware('throttle:20,1')->name('documents.upload.store');
Route::get('/quote/{token}', [QuotationPublicController::class, 'show'])->middleware('throttle:60,1')->name('quotations.public');
