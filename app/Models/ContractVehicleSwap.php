<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A car replaced during a rental contract (breakdown, accident, upgrade…). */
class ContractVehicleSwap extends Model
{
    use \App\Models\Concerns\Audited; // 19 Nov: in the audit log

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['swapped_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }

    public function fromCar(): BelongsTo
    {
        return $this->belongsTo(Car::class, 'from_car_id')->withTrashed();
    }

    public function toCar(): BelongsTo
    {
        return $this->belongsTo(Car::class, 'to_car_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
