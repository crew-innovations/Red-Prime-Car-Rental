<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An invoice or credit note issued for a booking (28 Oct, Priority 7.3).
 * The lines, seller and buyer are frozen when issued; later changes to the
 * booking never change an issued document. Wrong ones are voided (kept) or
 * corrected with a credit note, never deleted.
 *
 * "Tax Invoice" is printed only when VAT was switched on at issue (it then
 * carries the TRN). This PDF is a readable copy, not a structured UAE
 * e-invoice (that needs an accredited service provider; see docs/DEVLOG.md).
 */
class Invoice extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected $attributes = ['type' => 'invoice', 'status' => 'issued', 'currency' => 'AED'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'supply_date' => 'date',
            'is_tax_invoice' => 'boolean',
            'seller' => 'array',
            'buyer' => 'array',
            'lines' => 'array',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'invoice_id')->where('type', 'credit_note');
    }

    public function isCreditNote(): bool
    {
        return $this->type === 'credit_note';
    }

    public function title(): string
    {
        return match (true) {
            $this->isCreditNote() => $this->is_tax_invoice ? 'Tax Credit Note' : 'Credit Note',
            default => $this->is_tax_invoice ? 'Tax Invoice' : 'Invoice',
        };
    }

    public static function seller(): array
    {
        $s = Setting::values();
        $vat = Tax::enabled();

        return array_filter([
            'name' => ($s['company_legal_name'] ?? null) ?: ($s['company_name'] ?? 'RED PRIME RENTALS'),
            'trade_name' => $s['company_name'] ?? null,
            'address' => $s['company_address'] ?? null,
            'phone' => $s['company_phone'] ?? null,
            'email' => $s['company_email'] ?? null,
            'licence' => $s['company_trade_licence'] ?? null,
            'trn' => $vat ? ($s['company_trn'] ?? null) : null,
        ], fn ($v) => filled($v));
    }

    public static function buyer(?Customer $c): array
    {
        return array_filter([
            'name' => $c?->type !== \App\Enums\CustomerType::Individual && $c?->company_name ? $c->company_name : $c?->name,
            'contact' => $c?->type !== \App\Enums\CustomerType::Individual && $c?->company_name ? $c->name : null,
            'address' => $c?->address,
            'phone' => $c?->phone,
            'email' => $c?->email,
            'trn' => Tax::enabled() ? $c?->trn : null,
        ], fn ($v) => filled($v));
    }

    /** Lines of a booking as they stand now (rental, extras, return charges). */
    public static function linesFor(Booking $b): array
    {
        $lines = [[
            'label' => trim(($b->bookable?->name ?? $b->service_type?->getLabel()).' · '.($b->start_at?->format('d M Y H:i') ?? '').' – '.($b->end_at?->format('d M Y H:i') ?? ''), ' ·–'),
            'detail' => $b->price_breakdown['base_label'] ?? ($b->quantity && $b->rate ? \App\Support\Pricing\PriceEngine::qtyText((float) $b->quantity).' × AED '.number_format((float) $b->rate, 2) : null),
            'qty' => (float) ($b->quantity ?: 1),
            'unit_price' => (float) ($b->rate ?: $b->base_amount),
            'amount' => (float) $b->base_amount,
        ]];
        foreach ((array) $b->extras as $l) {
            $lines[] = [
                'label' => ($l['label'] ?? 'Extra').(! empty($l['waived']) ? ' (waived)' : ''),
                'detail' => ! empty($l['qty']) && (float) $l['qty'] != 1 ? \App\Support\Pricing\PriceEngine::qtyText((float) $l['qty']).' × AED '.number_format((float) ($l['unit_price'] ?? 0), 2) : null,
                'qty' => (float) ($l['qty'] ?? 1),
                'unit_price' => (float) ($l['unit_price'] ?? $l['amount'] ?? 0),
                'amount' => (float) ($l['amount'] ?? 0),
            ];
        }
        if (! $b->extras && (float) $b->extras_amount > 0) {
            $lines[] = ['label' => 'Add-ons & delivery', 'qty' => 1, 'unit_price' => (float) $b->extras_amount, 'amount' => (float) $b->extras_amount];
        }
        foreach ((array) $b->charges as $key => $amount) {
            if ((float) $amount > 0) {
                $lines[] = ['label' => \App\Support\Options::chargeLabel($key).' (on return)', 'qty' => 1, 'unit_price' => (float) $amount, 'amount' => (float) $amount, 'charge' => true];
            }
        }

        return $lines;
    }

    /** Issue an invoice for what the booking costs now. One open invoice per booking. */
    public static function issueFor(Booking $b, ?string $notes = null): self
    {
        if (! in_array($b->status, \App\Enums\BookingStatus::billable(), true)) {
            throw ValidationException::withMessages(['invoice' => 'Invoices are issued for confirmed bookings only.']);
        }
        if (static::query()->where('booking_id', $b->id)->where('type', 'invoice')->where('status', 'issued')->exists()) {
            throw ValidationException::withMessages(['invoice' => 'This booking already has an invoice. Void it first, or issue a credit note.']);
        }

        return DB::transaction(function () use ($b, $notes) {
            $lines = self::linesFor($b);
            $charges = collect($lines)->where('charge', true)->sum('amount');
            $subtotal = round(collect($lines)->sum('amount'), 2);
            // VAT as on the booking (the rental part); return charges are shown without extra VAT.
            return static::create([
                'type' => 'invoice',
                'number' => self::nextNumber('invoice'),
                'booking_id' => $b->id,
                'customer_id' => $b->customer_id,
                'issued_on' => today(),
                'supply_date' => $b->start_at?->toDateString(),
                'is_tax_invoice' => Tax::enabled() && (float) $b->tax_amount > 0,
                'seller' => self::seller(),
                'buyer' => self::buyer($b->customer),
                'lines' => $lines,
                'subtotal' => $subtotal,
                'discount' => (float) $b->discount,
                'tax_rate' => $b->tax_rate,
                'tax_inclusive' => (bool) $b->tax_inclusive,
                'tax_amount' => (float) $b->tax_amount,
                'total' => round((float) $b->total + $charges, 2),
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /** A credit note for part or all of an invoice (refund, correction). */
    public static function creditFor(self $invoice, float $amount, string $reason): self
    {
        if ($invoice->isCreditNote() || $invoice->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'A credit note needs an issued invoice.']);
        }
        $credited = (float) $invoice->creditNotes()->where('status', 'issued')->sum('total');
        if ($amount <= 0 || $amount > (float) $invoice->total - $credited + 0.005) {
            throw ValidationException::withMessages(['amount' => 'The credit note can be at most AED '.number_format((float) $invoice->total - $credited, 2).'.']);
        }
        [$tax] = $invoice->is_tax_invoice ? Tax::apply($amount, (float) $invoice->tax_rate, true) : [0.0];

        return DB::transaction(fn () => static::create([
            'type' => 'credit_note',
            'number' => self::nextNumber('credit_note'),
            'booking_id' => $invoice->booking_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'issued_on' => today(),
            'supply_date' => $invoice->supply_date,
            'is_tax_invoice' => $invoice->is_tax_invoice,
            'seller' => self::seller(),
            'buyer' => $invoice->buyer,
            'lines' => [['label' => $reason, 'qty' => 1, 'unit_price' => round($amount, 2), 'amount' => round($amount, 2)]],
            'subtotal' => round($amount, 2),
            'tax_rate' => $invoice->tax_rate,
            'tax_inclusive' => true,
            'tax_amount' => $tax,
            'total' => round($amount, 2),
            'notes' => 'Credit note for invoice '.$invoice->number,
            'created_by' => auth()->id(),
        ]));
    }

    public function void(string $reason): void
    {
        if ($this->status === 'void') {
            return;
        }
        $this->update(['status' => 'void', 'void_reason' => $reason, 'voided_at' => now()]);
    }

    /** INV-00001, CN-00001 … in order, without gaps from other document types. */
    public static function nextNumber(string $type): string
    {
        $prefix = $type === 'credit_note' ? 'CN-' : 'INV-';
        $last = static::query()->where('type', $type)->lockForUpdate()->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
