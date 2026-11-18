<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 5 Nov (S3, B.7): one link per agent and lead, so an agent can accept a lead from an email or a
 * phone notification without logging in. Only the SHA-256 of the token is stored; it works once,
 * only for that agent, until the lead is taken or 24 hours have passed.
 */
class LeadClaimToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** Makes a token for the agent and returns the plain value (only ever put in the link). */
    public static function issue(Lead $lead, User $agent, int $hours = 24): string
    {
        $plain = Str::random(48);
        static::create([
            'lead_id' => $lead->id,
            'user_id' => $agent->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours($hours),
        ]);

        return $plain;
    }

    public static function findPlain(?string $plain): ?self
    {
        if (! is_string($plain) || strlen($plain) < 20 || strlen($plain) > 100) {
            return null;
        }

        return static::query()->where('token_hash', hash('sha256', $plain))->first();
    }

    public function usable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
