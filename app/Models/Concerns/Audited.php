<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Keeps a history of who changed what on important records. Quiet saves made
 * by the system itself (balances, statuses) are not logged.
 */
trait Audited
{
    /** Columns never written to the log. */
    protected static array $auditIgnore = ['updated_at', 'created_at', 'remember_token', 'password'];

    public static function bootAudited(): void
    {
        static::created(fn (Model $model) => static::writeAudit($model, 'created', [], $model->getAttributes()));

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            $old = array_intersect_key($model->getOriginal(), $changes);
            static::writeAudit($model, 'updated', $old, $changes);
        });

        static::deleted(fn (Model $model) => static::writeAudit($model, 'deleted', [], []));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => static::writeAudit($model, 'restored', [], []));
        }
    }

    protected static function writeAudit(Model $model, string $event, array $old, array $new): void
    {
        $ignore = array_flip(static::$auditIgnore);
        $old = array_diff_key($old, $ignore);
        $new = array_diff_key($new, $ignore);

        if ($event === 'updated' && $new === []) {
            return;
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $event === 'created' ? null : ($new ?: null),
        ]);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('created_at');
    }
}
