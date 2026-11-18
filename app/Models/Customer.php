<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'is_vip' => 'boolean',
            'tags' => 'array',
            'date_of_birth' => 'date',
            'next_follow_up_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
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

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopeFollowUpDue(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now());
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Revenue received from this customer (paid and partially paid income, excluding deposits). */
    public function totalSpend(): float
    {
        return Transaction::totals($this->transactions()->getQuery())['received'];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    // quotations() and documents() arrive in Milestone 2.

    /** Emails sent to this customer (4 Nov, 14.4). */
    public function emailLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    /**
     * Marketing emails (4 Nov, 14.3): announcements go only to customers who agreed and did not
     * unsubscribe. Quotations, bookings and invoices are sent either way.
     */
    public function marketingConsent(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => $this->marketing_consent_at !== null && $this->unsubscribed_at === null,
            set: function ($value) {
                $on = filter_var($value, FILTER_VALIDATE_BOOL);
                if ($on && ($this->marketing_consent_at === null || $this->unsubscribed_at !== null)) {
                    return ['marketing_consent_at' => now(), 'marketing_consent_source' => 'staff', 'unsubscribed_at' => null];
                }
                if (! $on && $this->marketing_consent_at !== null && $this->unsubscribed_at === null) {
                    return ['unsubscribed_at' => now()];
                }

                return [];
            },
        );
    }

    /** Real customers: prospects (an account made before they booked, 4 Nov 13) are left out. */
    public function scopeCustomers(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'prospect'));
    }

    public function isProspect(): bool
    {
        return $this->status === 'prospect';
    }

    public function scopeAcceptsMarketing(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('marketing_consent_at')->whereNull('unsubscribed_at')->whereNotNull('email')->where('email', '!=', '');
    }
}
