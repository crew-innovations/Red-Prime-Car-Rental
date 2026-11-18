<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** One saved PDF of a rental contract ("rental agreement history"). Every change makes a new one. */
class ContractVersion extends Model
{
    protected $guarded = ['id'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Short-lived signed link; the private-file route checks who is asking. */
    public function url(): string
    {
        return Storage::disk('private')->temporaryUrl($this->path, now()->addMinutes(30));
    }
}
