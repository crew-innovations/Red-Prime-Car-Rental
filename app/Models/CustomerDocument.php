<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A customer document (passport, licence…) stored on the private disk (Schedule A11). */
class CustomerDocument extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    /** Same defaults as the database, so new records are complete before they are reloaded. */
    protected $attributes = ['status' => 'received', 'uploaded_via' => 'staff'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // 4 Nov (brief 9.3): the customer hears when a document has to be sent again.
        static::saved(fn (CustomerDocument $d) => rescue(fn () => \App\Support\Notifications\EventHooks::document($d)));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Short-lived link that only works for logged-in staff. */
    public function viewUrl(): ?string
    {
        return $this->path ? Storage::disk('private')->temporaryUrl($this->path, now()->addHours(2)) : null; // 20 Nov: 2 h, pages stay open; login + permission are still checked
    }

    public function isValidOn($date): bool
    {
        return $this->status === DocumentStatus::Approved && (! $this->expires_on || $this->expires_on->gte($date));
    }

    public function review(DocumentStatus $status, ?string $note = null): void
    {
        $this->update([
            'status' => $status,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }
}
