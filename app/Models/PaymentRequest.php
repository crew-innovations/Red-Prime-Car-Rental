<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** An amount asked for online (payment link). Money is recorded only by App\Support\Payments\Payments::settle(). */
class PaymentRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    public const PURPOSES = [
        'advance' => 'Advance to confirm',
        'balance' => 'Balance',
        'security_deposit' => 'Refundable security deposit',
        'other' => 'Other amount',
    ];

    public const STATUSES = [
        'open' => 'Waiting for payment',
        'processing' => 'Customer at checkout',
        'paid' => 'Paid',
        'review' => 'Needs checking',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'refunded' => 'Refunded',
        'partly_refunded' => 'Partly refunded',
    ];

    public const COLORS = ['open' => 'warning', 'processing' => 'info', 'paid' => 'success', 'review' => 'danger', 'failed' => 'danger',
        'cancelled' => 'gray', 'expired' => 'gray', 'refunded' => 'gray', 'partly_refunded' => 'success'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'refunded_amount' => 'decimal:2', 'providers' => 'array', 'meta' => 'array',
            'expires_at' => 'datetime', 'paid_at' => 'datetime', 'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentRequest $r) {
            $r->token ??= Str::random(48);
            $r->created_by ??= auth()->id();
        });
        static::created(fn (PaymentRequest $r) => $r->forceFill(['number' => sprintf('PR-%05d', $r->id)])->saveQuietly());
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function url(): string
    {
        return route('pay.show', $this->token);
    }

    /** Our reference sent to the gateway for one attempt: PR-00001-2. */
    public function attemptRef(?int $attempt = null): string
    {
        return $this->number.'-'.($attempt ?? $this->attempts);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['open', 'processing', 'failed'], true) && ! ($this->expires_at && $this->expires_at->isPast());
    }

    public function isPaid(): bool
    {
        return in_array($this->status, ['paid', 'partly_refunded', 'refunded'], true);
    }

    public function refundable(): float
    {
        return $this->isPaid() ? max(0, round((float) $this->amount - (float) $this->refunded_amount, 2)) : 0.0;
    }

    public function forLabel(): string
    {
        return $this->booking?->reference ?? $this->quotation?->number ?? '—';
    }

    public function customerName(): ?string
    {
        return $this->customer?->name ?? $this->quotation?->customerName();
    }

    public function customerEmail(): ?string
    {
        return $this->customer?->email ?? $this->quotation?->customerEmail();
    }

    public function customerPhone(): ?string
    {
        return $this->customer?->phone ?? $this->quotation?->customerPhone();
    }

    /** Minor units (fils / cents). */
    public function minor(): int
    {
        return (int) round((float) $this->amount * 100);
    }
}
