<?php

namespace App\Models;

use App\Enums\FineStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A traffic fine (4 Nov, section 2): one per authority and ticket number. */
class TrafficFine extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    public const AUTHORITIES = ['Dubai Police', 'RTA Dubai', 'Abu Dhabi Police', 'Sharjah Police', 'Ajman Police', 'RAK Police', 'Fujairah Police', 'UAQ Police', 'Other'];

    protected function casts(): array
    {
        return [
            'status' => FineStatus::class,
            'offence_at' => 'datetime',
            'amount' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'charged_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function charges(): MorphMany
    {
        return $this->morphMany(BookingCharge::class, 'source');
    }

    public function viewUrl(): ?string
    {
        return $this->path ? Storage::disk('private')->temporaryUrl($this->path, now()->addMinutes(10)) : null;
    }

    public function title(): string
    {
        return $this->authority.' '.$this->ticket_no;
    }
}
