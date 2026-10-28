<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * One line of the audit log. Append-only: rows can be added, never changed or
 * removed from the app (brief 28 Oct-2, Phase 8). Who, role, IP, device and a
 * request id are filled in automatically. Secrets are never written: fields
 * named like passwords, tokens, keys or secrets are masked.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** Field names that are never stored in clear. */
    public const SECRET_FIELDS = '/pass(word)?|secret|token|api_?key|private_key|signature|cvv|card_number|remember/i';

    /** Plain names for the events, used by the filters. */
    public const EVENTS = [
        'created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'restored' => 'Restored',
        'override' => 'Override', 'login' => 'Signed in', 'logout' => 'Signed out', 'login_failed' => 'Failed sign-in',
        'export' => 'Export', 'settings' => 'Settings changed', 'permissions' => 'Roles / permissions changed',
        'download' => 'Document downloaded', 'invite' => 'Login link created', 'integration' => 'Integration changed',
        'payment' => 'Online payment', 'webhook' => 'Webhook',
    ];

    protected static ?string $requestId = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $user = auth()->user();
            $log->user_id ??= $user?->getKey();
            if ($user && ! $log->role && method_exists($user, 'getRoleNames')) {
                $log->role = $user->getRoleNames()->first();
            }
            if (app()->bound('request') && ($request = request()) && ! app()->runningInConsole()) {
                $log->ip_address ??= $request->ip();
                $log->user_agent ??= mb_substr((string) $request->userAgent(), 0, 255) ?: null;
            }
            $log->request_id ??= self::requestId();
            $log->old_values = self::mask($log->old_values);
            $log->new_values = self::mask($log->new_values);
        });

        static::updating(fn () => throw new LogicException('Audit log lines cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log lines cannot be deleted.'));
    }

    /** One id per web request, so all lines written by one click can be found together. */
    public static function requestId(): string
    {
        if (static::$requestId === null) {
            $header = app()->bound('request') ? (string) request()->header('X-Request-Id') : '';
            static::$requestId = preg_match('/^[A-Za-z0-9\-]{8,40}$/', $header) ? $header : (string) Str::uuid();
        }

        return static::$requestId;
    }

    public static function resetRequestId(): void
    {
        static::$requestId = null;
    }

    /** Masks secret fields, also inside nested arrays. */
    public static function mask(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_FIELDS, $key)) {
                $values[$key] = filled($value) ? '••••••' : $value;
            } elseif (is_array($value)) {
                $values[$key] = self::mask($value);
            }
        }

        return $values;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Record something that is not a plain model change (e.g. an override). */
    public static function note(Model $model, string $event, string $note, array $values = []): void
    {
        static::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'new_values' => $values ?: null,
            'note' => mb_substr($note, 0, 255),
        ]);
    }

    /** An event about the system rather than one record (sign-in, settings, export). */
    public static function event(string $event, string $note, array $values = [], ?string $outcome = null, ?string $type = null, $userId = null): self
    {
        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'event' => $event,
            'outcome' => $outcome,
            'auditable_type' => $type,
            'auditable_id' => null,
            'new_values' => $values ?: null,
            'note' => mb_substr($note, 0, 255),
        ]);
    }
}
