<?php

use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\QuotationPublicController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Landing page of the CRM domain: what this system is, plus Team and Customer login buttons.
Route::get('/', fn () => view('public.home'))->name('home');

// The CRM domain is never indexed (the website domain answers robots.txt itself, routes/website.php).
Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']))->name('crm.robots');

// Private files (customer and driver documents, handover / return photos): a valid
// signed link AND a logged-in user allowed to see that file (App\Support\PrivateFiles).
Route::get('/crm/private-file/{path}', function (Request $request, string $path) {
    $user = $request->user();
    abort_if(str_contains($path, '..'), 404);
    $allowed = $user && \App\Support\PrivateFiles::allows($user, $path);

    // Identity papers (customer and driver documents): every opening and every refusal is logged.
    $doc = \App\Models\CustomerDocument::where('path', $path)->first() ?? \App\Models\DriverDocument::where('path', $path)->first();
    if ($doc || ! $allowed) {
        rescue(fn () => \App\Models\AuditLog::create([
            'event' => 'download', 'outcome' => $allowed ? null : 'denied',
            'auditable_type' => $doc?->getMorphClass(), 'auditable_id' => $doc?->getKey(),
            'note' => mb_substr(($allowed ? 'Opened ' : 'Refused: ').($doc ? str_replace('_', ' ', (string) $doc->type).' document' : 'private file').' '.basename($path), 0, 255),
        ]), report: false);
    }
    abort_unless($allowed, 403);

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
Route::post('/quote/{token}/accept', [QuotationPublicController::class, 'accept'])->middleware('throttle:10,1')->name('quotations.public.accept');
Route::post('/quote/{token}/decline', [QuotationPublicController::class, 'decline'])->middleware('throttle:10,1')->name('quotations.public.decline');

// 18 Nov: the customer reads and signs the rental contract from a link (no login, single use, expires).
Route::get('/sign/{token}', [\App\Http\Controllers\ContractSignController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{40,64}')->name('contracts.sign');
Route::post('/sign/{token}', [\App\Http\Controllers\ContractSignController::class, 'store'])->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{40,64}')->name('contracts.sign.store');
// Rental contract PDF (latest version) for staff and the customer's own account.
Route::get('/documents/contract/{contract}', [\App\Http\Controllers\DocumentPdfController::class, 'contract'])->middleware('throttle:60,1')->whereNumber('contract')->name('pdf.contract');

// Shared lead forms (28 Oct-2, Phase 16).
// 5 Nov (S3, B.7): accept a lead from the email or phone notification. GET only shows it; POST accepts.
Route::get('/lead-claim/{token}', [\App\Http\Controllers\LeadClaimController::class, 'show'])->middleware('throttle:lead-claim')->where('token', '[A-Za-z0-9]{20,100}')->name('lead-claim.show');
Route::post('/lead-claim/{token}', [\App\Http\Controllers\LeadClaimController::class, 'accept'])->middleware('throttle:lead-claim')->where('token', '[A-Za-z0-9]{20,100}')->name('lead-claim.accept');

Route::get('/f/{token}', [\App\Http\Controllers\LeadFormController::class, 'show'])->middleware('throttle:60,1')->name('forms.show');
Route::post('/f/{token}', [\App\Http\Controllers\LeadFormController::class, 'store'])->middleware('throttle:10,1')->name('forms.store');

// Online payment links (28 Oct-2, Phase 13). The money is recorded only from the provider's verified answer.
Route::get('/pay/{token}', [\App\Http\Controllers\PaymentPageController::class, 'show'])->middleware('throttle:60,1')->name('pay.show');
Route::post('/pay/{token}/{provider}', [\App\Http\Controllers\PaymentPageController::class, 'start'])->middleware('throttle:10,1')->whereIn('provider', array_keys(\App\Support\Payments\Payments::GATEWAYS))->name('pay.start');
Route::get('/pay/{token}/return/{provider}', [\App\Http\Controllers\PaymentPageController::class, 'back'])->middleware('throttle:30,1')->whereIn('provider', array_keys(\App\Support\Payments\Payments::GATEWAYS))->name('pay.return');
Route::post('/quote/{token}/pay', [QuotationPublicController::class, 'pay'])->middleware('throttle:10,1')->name('quotations.public.pay');

// Customer documents as PDF (28 Oct). Logged-in staff and customers; access checked per record.
Route::prefix('documents')->middleware('throttle:60,1')->group(function () {
    Route::get('/quotation/{quotation}', [\App\Http\Controllers\DocumentPdfController::class, 'quotation'])->whereNumber('quotation')->name('pdf.quotation');
    Route::get('/booking/{booking}/confirmation', [\App\Http\Controllers\DocumentPdfController::class, 'confirmation'])->whereNumber('booking')->name('pdf.confirmation');
    Route::get('/booking/{booking}/statement', [\App\Http\Controllers\DocumentPdfController::class, 'statement'])->whereNumber('booking')->name('pdf.statement');
    Route::get('/invoice/{invoice}', [\App\Http\Controllers\DocumentPdfController::class, 'invoice'])->whereNumber('invoice')->name('pdf.invoice');
    Route::get('/receipt/{transaction}', [\App\Http\Controllers\DocumentPdfController::class, 'receipt'])->whereNumber('transaction')->name('pdf.receipt');
    Route::get('/job-report/{report}', [\App\Http\Controllers\DocumentPdfController::class, 'jobReport'])->whereNumber('report')->name('job-report');
});
Route::get('/quote/{token}/pdf', [\App\Http\Controllers\DocumentPdfController::class, 'publicQuotation'])->middleware('throttle:30,1')->name('quotations.public.pdf');

// "Choose your password" link for driver and customer logins (signed, expires, single use).
Route::get('/invite/{user}', [\App\Http\Controllers\AccountInviteController::class, 'show'])->middleware(['signed:relative', 'throttle:30,1'])->whereNumber('user')->name('account.invite');
Route::post('/invite/{user}', [\App\Http\Controllers\AccountInviteController::class, 'store'])->middleware(['signed:relative', 'throttle:10,1'])->whereNumber('user')->name('account.invite.store');

// Installable apps and push notifications (4 Nov 2026, brief 9 / 16).
Route::get('/manifest/{app}.webmanifest', [\App\Http\Controllers\Pwa\AppController::class, 'manifest'])->whereIn('app', \App\Support\Push\Apps::KEYS)->name('pwa.manifest');
Route::get('/sw.js', [\App\Http\Controllers\Pwa\AppController::class, 'worker'])->name('pwa.worker');
Route::get('/pwa/offline', [\App\Http\Controllers\Pwa\AppController::class, 'offline'])->name('pwa.offline');
Route::get('/app/{app}', fn (string $app) => redirect(rtrim(\App\Support\Push\Apps::get($app)['scope'], '/').'/install'))->whereIn('app', \App\Support\Push\Apps::KEYS)->name('pwa.short');
// Install pages live inside each app's scope, so the browser offers "Install".
Route::get('/crm/driver/install', [\App\Http\Controllers\Pwa\AppController::class, 'install'])->defaults('app', 'driver')->name('pwa.install.driver');
Route::get('/crm/install', [\App\Http\Controllers\Pwa\AppController::class, 'install'])->defaults('app', 'staff')->name('pwa.install.staff');
Route::get('/account/install', [\App\Http\Controllers\Pwa\AppController::class, 'install'])->defaults('app', 'customer')->name('pwa.install.customer');
Route::post('/device', [\App\Http\Controllers\Pwa\DeviceController::class, 'store'])->middleware(['auth', 'throttle:30,1'])->name('pwa.device');

// Driver pages moved under /crm/driver (4 Nov, the driver app's own scope); old links still work.
Route::get('/crm/my-jobs', fn (\Illuminate\Http\Request $r) => redirect('/crm/driver/jobs'.($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301));
Route::get('/crm/my-jobs/{job}', fn (int $job) => redirect('/crm/driver/jobs/'.$job, 301))->whereNumber('job');

// Unsubscribe from announcements (4 Nov, 14.3): a signed link in each marketing email, no login needed.
Route::get('/unsubscribe/{customer}', function (\Illuminate\Http\Request $request, int $customer) {
    $c = \App\Models\Customer::find($customer);
    if ($c && ! $c->unsubscribed_at) {
        $c->forceFill(['unsubscribed_at' => now()])->saveQuietly();
        \App\Models\AuditLog::note($c, 'email', 'Unsubscribed from announcements (link in an email)');
    }

    return response()->view('emails.unsubscribed', ['company' => \App\Support\Mail\Branding::name()]);
})->middleware(['signed:relative', 'throttle:20,1'])->whereNumber('customer')->name('email.unsubscribe');

// Driver phone (4 Nov, 15): steps / late / decline with the phone's action id, and live location (setting).
Route::middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::post('/crm/driver/jobs/{job}/action', [\App\Http\Controllers\Driver\DriverApiController::class, 'action'])->whereNumber('job')->name('driver.action');
    Route::post('/crm/driver/location', [\App\Http\Controllers\Driver\DriverApiController::class, 'location'])->name('driver.location');
});

// 6 Nov (limousine L3): live tracking for the customer. Signed links only (no login); they expire.
Route::get('/track/{booking}', [\App\Http\Controllers\Public\TrackController::class, 'show'])->middleware(['signed', 'throttle:60,1'])->name('track.show');
Route::get('/track/{booking}/data', [\App\Http\Controllers\Public\TrackController::class, 'data'])->middleware(['signed', 'throttle:20,1'])->name('track.data');
Route::post('/track/{booking}/pay', [\App\Http\Controllers\Public\TrackController::class, 'pay'])->middleware(['signed', 'throttle:10,1'])->name('track.pay');
