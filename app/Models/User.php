<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Everyone who logs in: staff, agents and drivers use the CRM (/crm),
 * customers use their account area (/account). One table, one login system.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Audited, HasFactory, HasRoles, Notifiable;

    /** Cars this driver normally drives. */
    public function assignedCars(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Car::class, 'car_driver')->withTimestamps();
    }

    public function driverBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'driver_id');
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function driverDocuments(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(BookingJob::class, 'driver_id');
    }

    /** The customer record a customer account belongs to. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'notes',
        'password',
        'is_active',
        'login_enabled',
        'customer_id',
    ];

    /** Same defaults as the database, so new users are complete before they are reloaded. */
    protected $attributes = ['is_active' => true, 'login_enabled' => true];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'login_enabled' => 'boolean',
            'invited_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isCustomerAccount(): bool
    {
        return $this->customer_id !== null && $this->hasRole('Customer');
    }

    /** Only roles that are not Customer count for the CRM. */
    public function hasCrmRole(): bool
    {
        $roles = $this->getRoleNames();

        return $roles->isEmpty() || $roles->diff(['Customer'])->isNotEmpty();
    }

    public function isDriverOnly(): bool
    {
        $roles = $this->getRoleNames()->diff(['Customer']);

        return $roles->count() === 1 && $roles->first() === 'Driver';
    }

    /** Who may log in where. Checked on every request, so switching an account off works at once. */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active || ! ($this->login_enabled ?? true)) {
            return false;
        }

        if ($panel->getId() === 'account') {
            return $this->isCustomerAccount() && (bool) Setting::read('customer_accounts_enabled', true);
        }

        if (! $this->hasCrmRole()) {
            return false; // customer-only accounts never see the CRM
        }

        if ($this->isDriverOnly()) {
            return (bool) Setting::read('driver_login_enabled', true);
        }

        return true;
    }
}
