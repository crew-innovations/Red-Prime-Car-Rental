<?php

namespace App\Models;

use App\Support\Integrations\Registry;
use Illuminate\Database\Eloquent\Model;

/**
 * One outside service (Google Maps, a payment gateway, a social platform).
 * Secrets are encrypted at rest and hidden from arrays / JSON; the history log
 * only records which secret names changed, never their values.
 */
class Integration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secrets'];

    public const STATUSES = [
        'not_set_up' => 'Not set up',
        'configured' => 'Details saved, not tested',
        'connected' => 'Connected',
        'error' => 'Error',
        'expired' => 'Needs reconnecting',
        'blocked' => 'Waiting for the provider',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'permissions' => 'array',
            'token_expires_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public static function for(string $provider): self
    {
        return static::firstOrNew(['provider' => $provider], ['enabled' => false, 'mode' => 'test', 'status' => 'not_set_up']);
    }

    public function definition(): array
    {
        return Registry::get($this->provider) ?? [];
    }

    public function label(): string
    {
        return $this->definition()['label'] ?? $this->provider;
    }

    /** A secret for the current mode (test / live keys are kept apart). */
    public function secret(string $name): ?string
    {
        $all = (array) ($this->secrets ?? []);
        $value = $all[$this->mode.'.'.$name] ?? $all[$name] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function hasSecret(string $name, ?string $mode = null): bool
    {
        $all = (array) ($this->secrets ?? []);

        return filled($all[($mode ?? $this->mode).'.'.$name] ?? null) || filled($all[$name] ?? null);
    }

    /** Saves typed secrets for a mode; an empty field keeps the saved one. Returns the names that changed. */
    public function putSecrets(array $typed, string $mode): array
    {
        $all = (array) ($this->secrets ?? []);
        $changed = [];
        foreach ($typed as $name => $value) {
            if (blank($value)) {
                continue;
            }
            $key = $mode.'.'.$name;
            if (($all[$key] ?? null) !== $value) {
                $all[$key] = trim((string) $value);
                $changed[] = $key;
            }
        }
        $this->secrets = $all;

        return $changed;
    }

    public function forgetSecrets(): void
    {
        $this->secrets = [];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }

    /** Usable right now: switched on and its required secrets are saved. Says nothing about the provider side. */
    public function isReady(): bool
    {
        if (! $this->exists || ! $this->enabled) {
            return false;
        }
        foreach ($this->definition()['required'] ?? [] as $name) {
            if (! $this->hasSecret($name)) {
                return false;
            }
        }

        return true;
    }

    public function isConnected(): bool
    {
        return $this->isReady() && $this->status === 'connected';
    }

    public function recordTest(bool $ok, ?string $error = null, ?string $account = null, array $permissions = []): void
    {
        $this->forceFill([
            'status' => $ok ? 'connected' : 'error',
            'last_tested_at' => now(),
            'last_success_at' => $ok ? now() : $this->last_success_at,
            'last_error' => $ok ? null : mb_substr((string) $error, 0, 500),
            'account_label' => $ok ? ($account ?? $this->account_label) : $this->account_label,
            'permissions' => $permissions ?: $this->permissions,
        ])->save();
    }
}
