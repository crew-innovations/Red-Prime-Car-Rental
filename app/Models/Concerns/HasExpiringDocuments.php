<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Flags registration / licence / insurance / service dates that are close or
 * overdue. Models define: protected function expiringDocuments(): array
 * returning ['Label' => 'date_column', ...].
 */
trait HasExpiringDocuments
{
    /** Days before a date at which it is flagged in the CRM. */
    public const EXPIRY_WARNING_DAYS = 30;

    /** @return array<string, string> label => date column */
    abstract public static function expiringDocuments(): array;

    public function scopeDocumentsDueSoon(Builder $query): Builder
    {
        $limit = today()->addDays(self::EXPIRY_WARNING_DAYS);

        return $query->where(function (Builder $q) use ($limit) {
            foreach (static::expiringDocuments() as $column) {
                $q->orWhere($column, '<=', $limit);
            }
            // 19 Nov: cars also when the service is due by km (within 1,000 km).
            if ($q->getModel() instanceof \App\Models\Car) {
                $q->orWhere(fn (Builder $k) => $k->whereNotNull('next_service_km')->whereRaw('next_service_km <= COALESCE(mileage, 0) + 1000'));
            }
        });
    }

    /** @return array<string, string> label => human message, for items needing attention */
    public function documentAlerts(): array
    {
        $alerts = [];

        foreach (static::expiringDocuments() as $label => $column) {
            $date = $this->{$column};

            if (! $date) {
                continue;
            }

            $days = (int) today()->diffInDays($date, false);

            if ($days < 0) {
                $alerts[$label] = "{$label} overdue by ".abs($days).' days';
            } elseif ($days <= self::EXPIRY_WARNING_DAYS) {
                $alerts[$label] = $days === 0 ? "{$label} due today" : "{$label} due in {$days} days";
            }
        }

        return $alerts;
    }
}
