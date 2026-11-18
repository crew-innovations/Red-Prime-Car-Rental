<?php

use App\Http\Controllers\Website\SiteController;
use Illuminate\Support\Facades\Route;

/*
 * Public website (M3). Loaded from routes/web.php BEFORE the CRM landing page, so
 * "/" on the website domain shows the website and "/" on the CRM domain stays the
 * CRM landing page. English at the root, Arabic under /ar.
 *
 * Stateless on purpose: no session cookie and no CSRF for visitors. Forms on the
 * website send to the public API (/api/v1), which has its own rate limits and checks.
 */
$pages = function () {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    Route::get('/cars', [SiteController::class, 'cars'])->name('cars');
    Route::get('/cars/{slug}', [SiteController::class, 'car'])->where('slug', '[A-Za-z0-9\-]+')->name('car');
    Route::get('/yachts', [SiteController::class, 'yachts'])->name('yachts');
    Route::get('/yachts/{slug}', [SiteController::class, 'yacht'])->where('slug', '[A-Za-z0-9\-]+')->name('yacht');
    Route::get('/yacht-trips', [SiteController::class, 'trips'])->name('trips');
    Route::get('/yacht-trips/{slug}', [SiteController::class, 'trip'])->where('slug', '[A-Za-z0-9\-]+')->name('trip');
    Route::get('/packages', [SiteController::class, 'packages'])->name('packages');
    Route::get('/packages/{slug}', [SiteController::class, 'package'])->where('slug', '[A-Za-z0-9\-]+')->name('package');
    Route::get('/limousine', [SiteController::class, 'limousine'])->name('limousine');
    Route::get('/services', [SiteController::class, 'services'])->name('services');
    Route::get('/services/{slug}', [SiteController::class, 'service'])->where('slug', '[a-z0-9\-]+')->name('service');
    Route::get('/brands', [SiteController::class, 'brands'])->name('brands');
    Route::get('/brands/{slug}', [SiteController::class, 'brand'])->where('slug', '[a-z0-9\-]+')->name('brand');
    Route::get('/car-types/{slug}', [SiteController::class, 'type'])->where('slug', '[a-z0-9\-]+')->name('type');
    Route::get('/offers', [SiteController::class, 'offers'])->name('offers');
    Route::get('/blog', [SiteController::class, 'blog'])->name('blog');
    Route::get('/blog/{slug}', [SiteController::class, 'post'])->where('slug', '[a-z0-9\-]+')->name('post');
    Route::get('/delivery-areas', [SiteController::class, 'areas'])->name('areas');
    Route::get('/delivery-areas/{slug}', [SiteController::class, 'area'])->where('slug', '[a-z0-9\-]+')->name('area');
    Route::get('/how-it-works', [SiteController::class, 'how'])->name('how');
    Route::get('/gallery', [SiteController::class, 'gallery'])->name('gallery');
    Route::get('/about', [SiteController::class, 'about'])->name('about');
    Route::get('/contact', [SiteController::class, 'contact'])->name('contact');
    Route::get('/faq', [SiteController::class, 'faq'])->name('faq');
    Route::get('/terms', [SiteController::class, 'terms'])->name('terms');
    Route::get('/privacy', [SiteController::class, 'privacy'])->name('privacy');
};

$middleware = [\Illuminate\Routing\Middleware\SubstituteBindings::class];
$domain = config('website.domain');

$group = Route::middleware($middleware);
if ($domain) {
    // www.redprimerentals.com → redprimerentals.com (same path).
    Route::domain('www.'.$domain)->get('/{any?}', fn (\Illuminate\Http\Request $r) => redirect()->away('https://'.$domain.$r->getRequestUri(), 301))->where('any', '.*');
    $group = $group->domain($domain);
} else {
    $group = $group->prefix(config('website.path', 'site'));
}

$group->name('site.')->group(function () use ($pages) {
    Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('sitemap');
    Route::get('/robots.txt', [SiteController::class, 'robots'])->name('robots');

    Route::middleware('site.locale:en')->group($pages);
    Route::prefix('ar')->name('ar.')->middleware('site.locale:ar')->group($pages);
});
