<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** A third-party owner or rental partner whose vehicles RED PRIME uses (29 Oct). */
class Supplier extends Model implements HasMedia
{
    use Audited, InteractsWithMedia, SoftDeletes;

    protected $guarded = ['id'];

    public const TYPES = [
        'car_owner' => 'Car owner', 'rental_company' => 'Rental company', 'yacht_owner' => 'Yacht owner',
        'chauffeur_company' => 'Chauffeur / limousine company', 'other' => 'Other',
    ];

    public const STATUSES = ['active' => 'Active', 'pending' => 'Pending approval', 'inactive' => 'Inactive', 'blocked' => 'Blocked'];

    public const COLORS = ['active' => 'success', 'pending' => 'warning', 'inactive' => 'gray', 'blocked' => 'danger'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'opening_balance_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Supplier $s) => $s->created_by ??= auth()->id());
        // Suppliers with history are never removed (switch them off instead).
        static::deleting(function (Supplier $s) {
            if ($s->isForceDeleting() || $s->bills()->exists() || Booking::withTrashed()->where('supplier_id', $s->id)->exists() || $s->payments()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['supplier' => 'This supplier has bookings or money records. Set it to Inactive instead.']);
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents')->useDisk('private');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(SupplierRate::class);
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    public function yachts(): HasMany
    {
        return $this->hasMany(Yacht::class);
    }

    /** Bills = expense rows linked to the supplier. */
    public function bills(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active';
    }
}
