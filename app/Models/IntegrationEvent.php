<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Webhook received or API call made for an integration: status, attempts,
 * errors. The raw payload is encrypted and hidden; only a short summary is shown.
 */
class IntegrationEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'payload' => 'encrypted', 'next_retry_at' => 'datetime', 'attempts' => 'integer'];
    }

    public static function log(string $provider, string $direction, string $status, ?string $event = null, ?string $externalId = null, array $summary = [], ?string $error = null, ?string $payload = null): self
    {
        return static::create([
            'provider' => $provider, 'direction' => $direction, 'status' => $status, 'event' => $event,
            'external_id' => $externalId, 'summary' => $summary ?: null, 'error' => $error ? mb_substr($error, 0, 500) : null,
            'payload' => $payload !== null ? mb_substr($payload, 0, 60000) : null,
        ]);
    }
}
