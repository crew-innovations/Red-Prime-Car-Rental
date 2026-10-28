<?php

namespace App\Models;

use App\Enums\DriveMode;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPricing;
use App\Support\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Quotation (Schedule A8). The price is locked once sent; accepting a quote
 * does not reserve the car / yacht — that only happens when it is converted
 * into a booking and availability is checked again.
 */
class Quotation extends Model
{
    use Audited, HasPricing, SoftDeletes;

    protected $guarded = ['id'];

    /** Fields that make up the agreed offer; locked while Sent / Accepted. */
    public const PRICE_FIELDS = [
        'service_type', 'bookable_id', 'trip_date_id', 'package_departure_id', 'drive_mode', 'start_at', 'end_at', 'guests',
        'delivery_method', 'return_method',
        'base_amount', 'extras', 'discount', 'tax_rate', 'tax_inclusive', 'security_deposit', 'advance_required',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'service_type' => ServiceType::class,
            'drive_mode' => DriveMode::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'extras' => 'array',
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'extras_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'standard_amount' => 'decimal:2',
            'price_breakdown' => 'array',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'security_deposit' => 'decimal:2',
            'advance_required' => 'decimal:2',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Quotation $q) {
            // Once sent / accepted the agreed price never changes silently.
            $was = $q->exists ? QuotationStatus::tryFrom((string) ($q->getRawOriginal('status') ?? '')) : null;
            if ($was && ! $was->isEditable() && $q->isDirty(self::PRICE_FIELDS)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'data.base_amount' => 'This quotation is '.$was->getLabel().'. Use "Revise" before changing the price or dates.',
                ]);
            }
            if ($was && ! $was->isEditable()) {
                return;
            }

            if ($q->bookable_id && $q->service_type) {
                $q->bookable_type = $q->service_type->value;
            }
            if ($q->service_type !== ServiceType::Car) {
                $q->drive_mode = null;
                $q->delivery_method = null;
                $q->return_method = null;
            }
            $q->applyPricing(); // the server works the price out again (Priority 2.2)
            $q->extras_amount = round(collect($q->extras ?? [])->sum(fn ($line) => (float) ($line['amount'] ?? 0)), 2);
            $net = (float) $q->base_amount + (float) $q->extras_amount - (float) $q->discount;
            [$q->tax_amount, $q->total] = Tax::apply($net, $q->tax_rate !== null ? (float) $q->tax_rate : null, (bool) $q->tax_inclusive);
            $q->created_by ??= auth()->id();
            $q->public_token ??= Str::random(40);
        });

        static::created(function (Quotation $q) {
            $q->forceFill(['number' => sprintf('QT-%05d', $q->id)])->saveQuietly();
        });
    }

    /** Mark quotes past their valid-until date as expired (kept, never deleted). */
    public static function expireOverdue(): int
    {
        $count = 0;
        static::query()
            ->whereIn('status', [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', today())
            ->get()
            ->each(function (Quotation $q) use (&$count) {
                $q->update(['status' => QuotationStatus::Expired, 'expired_at' => now()]);
                $count++;
            });

        return $count;
    }

    public function publicUrl(): string
    {
        return route('quotations.public', ['token' => $this->public_token]);
    }

    public function title(): string
    {
        return trim(($this->number ?? 'Quotation').' · '.($this->bookable?->name ?? $this->service_type?->getLabel()));
    }

    public function customerName(): ?string
    {
        return $this->customer?->name ?? $this->lead?->name;
    }

    public function customerPhone(): ?string
    {
        return $this->customer?->whatsapp ?: $this->customer?->phone ?: $this->lead?->whatsapp ?: $this->lead?->phone;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function tripDate(): BelongsTo
    {
        return $this->belongsTo(TripDate::class);
    }

    public function packageDeparture(): BelongsTo
    {
        return $this->belongsTo(PackageDeparture::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
