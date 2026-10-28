<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A driver's passport, ID, visa, licence or other paper, kept on the private disk.
 * Adding a new copy of the same type keeps the old one (marked replaced) for the record.
 */
class DriverDocument extends Model
{
    use Audited;

    protected $guarded = ['id'];

    public const TYPES = [
        'passport' => 'Passport',
        'emirates_id' => 'Emirates ID / national ID',
        'visa' => 'Visa / residency',
        'licence' => 'Driving licence',
        'captain_licence' => 'Captain / boat master licence',
        'permit' => 'Permit / certificate',
        'other' => 'Other document',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'encrypted',
            'classes' => 'array',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'replaced_at' => 'datetime',
            'reminded_soon_at' => 'datetime',
            'reminded_expired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A new expiry date means new reminders.
        static::updating(function (DriverDocument $doc) {
            if ($doc->isDirty('expires_on')) {
                $doc->reminded_soon_at = null;
                $doc->reminded_expired_at = null;
            }
        });

        // A new copy of a type replaces the previous current one (kept as history).
        static::created(function (DriverDocument $doc) {
            if ($doc->type === 'other') {
                return;
            }
            static::query()
                ->where('user_id', $doc->user_id)
                ->where('type', $doc->type)
                ->whereKeyNot($doc->getKey())
                ->whereNull('replaced_at')
                ->get()
                ->each(fn (DriverDocument $old) => $old->update(['replaced_at' => now()]));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('replaced_at');
    }

    public function typeLabel(): string
    {
        return $this->type === 'other' && filled($this->label) ? $this->label : (self::TYPES[$this->type] ?? ucfirst((string) $this->type));
    }

    public static function warnDays(): int
    {
        return max(0, (int) Setting::read('driver_doc_warn_days', 30));
    }

    /** valid / expiring / expired / no_expiry */
    public function expiryState(?Carbon $on = null): string
    {
        $on ??= today();
        if (! $this->expires_on) {
            return 'no_expiry';
        }
        if ($this->expires_on->lt($on)) {
            return 'expired';
        }

        return $this->expires_on->lte($on->copy()->addDays(self::warnDays())) ? 'expiring' : 'valid';
    }

    public function expiryLabel(): string
    {
        return match ($this->expiryState()) {
            'expired' => 'Expired '.$this->expires_on->format('d M Y'),
            'expiring' => 'Expires '.$this->expires_on->format('d M Y').' ('.today()->diffInDays($this->expires_on).' days)',
            'valid' => 'Valid until '.$this->expires_on->format('d M Y'),
            default => 'No expiry date',
        };
    }

    public function expiryColor(): string
    {
        return match ($this->expiryState()) {
            'expired' => 'danger',
            'expiring' => 'warning',
            'valid' => 'success',
            default => 'gray',
        };
    }

    /** Short-lived signed link (private-file route checks who is asking). */
    public function viewUrl(): ?string
    {
        return $this->path ? Storage::disk('private')->temporaryUrl($this->path, now()->addMinutes(10)) : null;
    }

    /** Last 4 characters only, for lists. */
    public function maskedNumber(): ?string
    {
        $n = (string) $this->number;

        return $n === '' ? null : (mb_strlen($n) > 4 ? '•••• '.mb_substr($n, -4) : $n);
    }
}
