<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * 18 Nov 2026: the rental contract of a car booking (meeting item 1, "same as on fleet").
 * The booking keeps the dates, car, price, money, handover and return; the contract adds what
 * makes it a contract: number, type, drivers, terms, signature, vehicle replacements, closing
 * summary and a PDF for every version.
 */
class RentalContract extends Model implements HasMedia
{
    use Audited, InteractsWithMedia, SoftDeletes;

    protected $guarded = ['id'];

    public const TYPES = ['individual' => 'Individual', 'company' => 'Company', 'monthly' => 'Monthly / long term'];

    public const STATUSES = ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];

    public const STATUS_COLORS = ['draft' => 'gray', 'open' => 'success', 'closed' => 'info', 'cancelled' => 'danger'];

    public const PERIODS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'next_invoice_on' => 'date',
            'signed_at' => 'datetime',
            'sign_token_expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'closing' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (RentalContract $c) {
            $prefix = trim((string) Setting::read('contract_number_prefix', 'RC')) ?: 'RC';
            $c->forceFill(['number' => sprintf('%s-%s-%05d', $prefix, $c->created_at?->format('Y') ?? date('Y'), $c->id)])->saveQuietly();
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('signature')->useDisk('private')->singleFile();
        $this->addMediaCollection('attachments')->useDisk('private');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(ContractDriver::class)->orderByDesc('is_main')->orderBy('id');
    }

    public function swaps(): HasMany
    {
        return $this->hasMany(ContractVehicleSwap::class)->orderBy('swapped_at');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class)->orderByDesc('version');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? (string) $this->type;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'open'], true);
    }

    public function latestVersion(): ?ContractVersion
    {
        return $this->versions()->first();
    }

    public function mainDriver(): ?ContractDriver
    {
        return $this->drivers()->where('is_main', true)->first();
    }
}
