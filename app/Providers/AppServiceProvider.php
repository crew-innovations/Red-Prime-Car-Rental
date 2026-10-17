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
            'customer' => Customer::class,
            'lead' => Lead::class,
            'user' => User::class,
            'transaction' => Transaction::class,
            'booking' => Booking::class,
            'quotation' => \App\Models\Quotation::class,
            'customer_document' => \App\Models\CustomerDocument::class,
        ]);

        // Fail loudly in development if a value is written to a column that does not exist.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Super Admin can do everything; other roles get the abilities in App\Support\Access.
        \Illuminate\Support\Facades\Gate::before(
            fn (User $user) => $user->hasRole('Super Admin') ? true : null
        );
        \App\Support\Access::register();

        // Private files are opened through a short-lived signed link (see routes/web.php).
        // Skipped while an old cached config (without the private disk) is still in place.
        if (config('filesystems.disks.private')) {
            \Illuminate\Support\Facades\Storage::disk('private')->buildTemporaryUrlsUsing(
                fn (string $path, \DateTimeInterface $expiration) => \Illuminate\Support\Facades\URL::temporarySignedRoute('private-file', $expiration, ['path' => $path])
            );
        }
    }
}
