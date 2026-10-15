<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Lead extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'service_type' => ServiceType::class,
            'preferred_date' => 'date',
            'converted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ];
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function interestedIn(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [LeadStatus::Converted, LeadStatus::Lost]);
    }

    public function isConverted(): bool
    {
        return $this->status === LeadStatus::Converted;
    }

    /**
     * Turn this lead into a customer. Re-uses an existing customer with the
     * same phone or email so the same person is never created twice.
     */
    public function convertToCustomer(): Customer
    {
        return DB::transaction(function () {
            $existing = ($this->phone || $this->email)
                ? Customer::query()->where(function ($q) {
                    if ($this->phone) {
                        $q->orWhere('phone', $this->phone)->orWhere('whatsapp', $this->phone);
                    }
                    if ($this->email) {
                        $q->orWhere('email', $this->email);
                    }
                })->first()
                : null;

            $customer = $this->customer
                ?? $existing
                ?? Customer::create([
                    'name' => $this->name,
                    'phone' => $this->phone,
                    'whatsapp' => $this->phone,
                    'email' => $this->email,
                    'lead_source_id' => $this->lead_source_id,
                    'assigned_to' => $this->assigned_to,
                ]);

            $this->update([
                'customer_id' => $customer->id,
                'status' => LeadStatus::Converted,
                'converted_at' => now(),
            ]);

            return $customer;
        });
    }
}
