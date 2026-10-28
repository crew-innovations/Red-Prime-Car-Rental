<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\User;
use App\Models\Yacht;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Short, stable names in polymorphic columns instead of class names.
        Relation::morphMap([
            'car' => Car::class,
            'yacht' => Yacht::class,
            'trip' => Trip::class,
            'package' => \App\Models\Package::class,
            'customer' => Customer::class,
            'lead' => Lead::class,
            'user' => User::class,
            'transaction' => Transaction::class,
            'booking' => Booking::class,
            'job_report' => \App\Models\JobReport::class,
            'quotation' => \App\Models\Quotation::class,
            'customer_document' => \App\Models\CustomerDocument::class,
            'driver_profile' => \App\Models\DriverProfile::class,
            'driver_document' => \App\Models\DriverDocument::class,
            'booking_job' => \App\Models\BookingJob::class,
            'damage_report' => \App\Models\DamageReport::class,
            'price_rule' => \App\Models\PriceRule::class,
            'price_extra' => \App\Models\PriceExtra::class,
            'invoice' => \App\Models\Invoice::class,
            'transfer_service' => \App\Models\TransferService::class,
            'vehicle_category' => \App\Models\VehicleCategory::class,
            'transfer_rate' => \App\Models\TransferRate::class,
            'transfer_fixed_route' => \App\Models\TransferFixedRoute::class,
            'integration' => \App\Models\Integration::class,
        ]);

        // Last login time on the driver / customer / team lists (a quiet save, not in the history),
        // and sign-in / sign-out / failed sign-in lines in the audit log (brief 28 Oct-2, Phase 8).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                rescue(fn () => \App\Models\AuditLog::event('login', 'Signed in'.($event->remember ? ' (remember me)' : ''), [], null, 'user', $event->user->getKey()), report: false);
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event) {
            if ($event->user instanceof User) {
                rescue(fn () => \App\Models\AuditLog::event('logout', 'Signed out', [], null, 'user', $event->user->getKey()), report: false);
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($event) {
            // Only the email typed is kept, never the password.
            $email = mb_substr((string) ($event->credentials['email'] ?? ''), 0, 120);
            rescue(fn () => \App\Models\AuditLog::event('login_failed', 'Failed sign-in for '.($email ?: 'unknown email'), ['email' => $email], 'failed', 'user', $event->user?->getKey()), report: false);
        });
        $this->app->terminating(fn () => \App\Models\AuditLog::resetRequestId());

        // Fail loudly in development if a value is written to a column that does not exist.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Super Admin can do everything; other roles get the abilities in App\Support\Access.
        \Illuminate\Support\Facades\Gate::before(
            fn (User $user) => $user->hasRole('Super Admin') ? true : null
        );
        \App\Support\Access::register();

        // Public website API: limits per visitor IP (catalogue reads, enquiries).
        \Illuminate\Support\Facades\RateLimiter::for('api-catalog', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($request->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('api-booking-requests', fn (\Illuminate\Http\Request $request) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($request->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perDay(50)->by($request->ip()),
        ]);

        // Websites allowed to call the API from a browser: .env plus Business settings.
        try {
            config(['cors.allowed_origins' => \App\Support\Website::allowedOrigins()]);
        } catch (\Throwable) {
            // Settings table not there yet (fresh install / migrating): keep the .env list.
        }

        // Private files are opened through a short-lived signed link (see routes/web.php).
        // The signature covers the path only (not scheme or host), so it also works behind
        // the hosting proxy, where the app may see http while the browser uses https.
        // Skipped while an old cached config (without the private disk) is still in place.
        if (config('filesystems.disks.private')) {
            \Illuminate\Support\Facades\Storage::disk('private')->buildTemporaryUrlsUsing(
                fn (string $path, \DateTimeInterface $expiration) => \Illuminate\Support\Facades\URL::temporarySignedRoute('private-file', $expiration, ['path' => $path], absolute: false)
            );
        }
    }
}
