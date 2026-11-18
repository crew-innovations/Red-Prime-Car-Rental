<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone or computer where someone uses the installed app (4 Nov, 16.2), with its web push
 * subscription. The push address and keys are encrypted; a device can be removed (lost phone).
 */
class UserDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['push_endpoint', 'push_keys'];

    public const APPS = ['driver' => 'Driver app', 'staff' => 'Staff app', 'customer' => 'Customer app'];

    protected function casts(): array
    {
        return [
            'push_endpoint' => 'encrypted',
            'push_keys' => 'encrypted:array',
            'installed' => 'boolean',
            'installed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_push_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canPush(): bool
    {
        return $this->permission === 'granted' && filled($this->push_endpoint) && filled($this->push_keys['p256dh'] ?? null);
    }

    public function clearPush(): void
    {
        $this->forceFill(['push_endpoint' => null, 'endpoint_hash' => null, 'push_keys' => null])->save();
    }
}
