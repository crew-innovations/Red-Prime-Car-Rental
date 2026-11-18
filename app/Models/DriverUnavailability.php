<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Time a driver or crew member cannot work (leave, appointment). Jobs cannot be given to them then. */
class DriverUnavailability extends Model
{
    use Audited, SoftDeletes;

    protected $table = 'driver_unavailability';

    protected $guarded = ['id'];

    public const REASONS = ['Day off', 'Leave', 'Sick', 'Appointment', 'Other'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        $same = $this->starts_at->isSameDay($this->ends_at);

        return ($this->reason ?: 'Not available').' · '.$this->starts_at->format('D d M H:i').' – '.$this->ends_at->format($same ? 'H:i' : 'D d M H:i');
    }
}
