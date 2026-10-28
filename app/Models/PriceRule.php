<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A special rate on top of the standard rates: a season, special dates
 * (Eid, New Year), weekends, or a rate for a whole category of cars / yachts.
 * "set" replaces the rate for one unit; "percent" moves the standard rate up
 * or down (negative = cheaper).
 */
class PriceRule extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    public const UNITS = [
        'car' => ['hour' => 'Per hour', 'day' => 'Per day', 'week' => 'Per week', 'month' => 'Per month', 'any' => 'Every rate (percent only)'],
        'yacht' => ['hour' => 'Per hour', 'half_day' => 'Half day', 'full_day' => 'Full day', 'any' => 'Every rate (percent only)'],
        'trip' => ['guest' => 'Per guest', 'trip' => 'Per trip', 'any' => 'Every rate (percent only)'],
        'package' => ['guest' => 'Per guest', 'package' => 'Per package', 'any' => 'Every rate (percent only)'],
    ];

    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'min_units' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weekdays' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PriceRule $rule) {
            $rule->created_by ??= auth()->id();
            if ($rule->applies_to !== 'item') {
                $rule->item_id = null;
            }
            if ($rule->applies_to !== 'category') {
                $rule->category = null;
            }
            if ($rule->service_type !== 'car') {
                $rule->drive_mode = null;
            }
            if ($rule->method === 'set' && $rule->unit === 'any') {
                throw \Illuminate\Validation\ValidationException::withMessages(['data.unit' => 'Pick which rate this replaces (per day, per hour...). "Every rate" only works with a percentage.']);
            }
            $rule->weekdays = $rule->weekdays ? array_values(array_map('intval', $rule->weekdays)) : null;
        });
    }

    public function item(): BelongsTo
    {
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->service_type) ?? Car::class;

        return $this->belongsTo($class, 'item_id')->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Is the rule in force on this date (date range and weekdays)? */
    public function appliesOn(Carbon $day): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_on && $day->copy()->startOfDay()->lt($this->starts_on)) {
            return false;
        }
        if ($this->ends_on && $day->copy()->startOfDay()->gt($this->ends_on)) {
            return false;
        }

        return empty($this->weekdays) || in_array($day->isoWeekday(), $this->weekdays, true);
    }

    /** item > category > all, then priority. */
    public function specificity(): int
    {
        return match ($this->applies_to) {
            'item' => 3,
            'category' => 2,
            default => 1,
        };
    }

    public function scopeLabel(): string
    {
        return match ($this->applies_to) {
            'item' => $this->item?->name ?? 'One item',
            'category' => 'All '.$this->category,
            default => 'All '.match ($this->service_type) {
                'car' => 'cars',
                'yacht' => 'yachts',
                'trip' => 'yacht trips',
                'package' => 'packages',
                default => $this->service_type,
            },
        };
    }

    public function effectText(): string
    {
        $unit = self::UNITS[$this->service_type][$this->unit] ?? $this->unit;
        if ($this->method === 'percent') {
            $n = (float) $this->amount;

            return ($n >= 0 ? '+' : '').rtrim(rtrim(number_format($n, 2), '0'), '.').'% '.($this->unit === 'any' ? 'on every rate' : strtolower($unit));
        }

        return 'AED '.number_format((float) $this->amount, 2).' '.strtolower($unit);
    }

    public function whenText(): string
    {
        $range = match (true) {
            $this->starts_on && $this->ends_on => $this->starts_on->format('d M Y').' – '.$this->ends_on->format('d M Y'),
            (bool) $this->starts_on => 'From '.$this->starts_on->format('d M Y'),
            (bool) $this->ends_on => 'Until '.$this->ends_on->format('d M Y'),
            default => 'Always',
        };
        if ($this->weekdays) {
            $range .= ' · '.collect($this->weekdays)->map(fn ($d) => self::WEEKDAYS[$d] ?? $d)->implode(', ');
        }

        return $range;
    }
}
