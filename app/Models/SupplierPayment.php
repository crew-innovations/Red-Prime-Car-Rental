<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money paid to a supplier, allocated to their bills (or the opening balance). Never an expense itself. */
class SupplierPayment extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (SupplierPayment $p) => $p->created_by ??= auth()->id());
        static::created(fn (SupplierPayment $p) => $p->forceFill(['number' => sprintf('SP-%05d', $p->id)])->saveQuietly());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
