<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        // Website first (its own domain, stateless, no "web" group), then the CRM and the API.
        using: function () {
            \Illuminate\Support\Facades\Route::get('/up', fn () => response('OK', 200))->name('health');
            require __DIR__.'/../routes/website.php';
            \Illuminate\Support\Facades\Route::middleware('api')->prefix('api')->group(__DIR__.'/../routes/api.php');
            \Illuminate\Support\Facades\Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['site.locale' => \App\Http\Middleware\SiteLocale::class]);
        // 5 Nov (S3): the phone notification's Accept button posts from the service worker, without a
        // CSRF token. The agent's own single-use token is the proof instead.
        $middleware->validateCsrfTokens(except: ['lead-claim/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Website pages that do not exist (or are not published): the website's own 404 page.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || ! \App\Support\Site\Site::isSiteRequest($request)) {
                return null;
            }
            $locale = \App\Support\Site\Site::localeFromPath($request);
            app()->setLocale($locale);

            return response()->view('site.404', ['seo' => ['title' => __('site.errors.not_found'), 'noindex' => true]], 404);
        });
    })->create();
