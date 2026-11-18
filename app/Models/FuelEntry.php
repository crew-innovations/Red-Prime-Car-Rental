<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 5 Nov (S5): one fill-up. From a driver's phone it waits for Accounts ("waiting"); confirming
 * creates the fuel expense for the car (and the booking, when the car was out). Never deleted:
 * a wrong one is rejected with a reason. See App\Support\Fuel\Fuel.
 */
class FuelEntry extends Model
{
    use Audited;

    protected $guarded = ['id'];

    public const STATUSES = ['waiting' => 'Waiting for Accounts', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected'];

    protected function casts(): array
    {
        return [
            'filled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'litres' => 'decimal:2',
            'amount' => 'decimal:2',
            'full_tank' => 'boolean',
            'km' => 'integer',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function pricePerLitre(): ?float
    {
        return (float) $this->litres > 0 ? round((float) $this->amount / (float) $this->litres, 2) : null;
    }
}
