<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A secure upload link sent to a customer. Only a hash of the token is
 * stored; the link stops working when it expires or is revoked.
 */
class DocumentRequest extends Model
{
    protected $guarded = ['id'];

    /** The plain token, only available right after creation. */
    public ?string $plainToken = null;

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public static function issue(Customer $customer, array $types, int $hours, ?int $bookingId = null): self
    {
        $token = Str::random(48);

        $request = static::create([
            'customer_id' => $customer->id,
            'booking_id' => $bookingId,
            'token_hash' => hash('sha256', $token),
            'types' => array_values($types),
            'expires_at' => now()->addHours(max(1, $hours)),
            'created_by' => auth()->id(),
        ]);
        $request->plainToken = $token;

        return $request;
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }

    public function url(): ?string
    {
        return $this->plainToken ? route('documents.upload', ['token' => $this->plainToken]) : null;
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }

    /** @return array<string, string> */
    public function typeLabels(): array
    {
        return collect($this->types ?? [])
            ->mapWithKeys(fn ($t) => [$t => \App\Support\Options::documentLabel($t)])
            ->all();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }
}
