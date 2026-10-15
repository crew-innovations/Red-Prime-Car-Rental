<?php

namespace App\Providers;

use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
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
        ]);

        // Catch N+1 queries and typos in attribute names during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Super Admin can do everything; per-role permissions arrive in Milestone 2.
        \Illuminate\Support\Facades\Gate::before(
            fn (User $user) => $user->hasRole('Super Admin') ? true : null
        );
    }
}
