<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A driver named on a rental contract: the main driver (the customer) or an additional driver. */
class ContractDriver extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'date_of_birth' => 'date',
            'id_expires_on' => 'date',
            'licence_issued_on' => 'date',
            'licence_expires_on' => 'date',
            'charge' => 'decimal:2',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }
}
