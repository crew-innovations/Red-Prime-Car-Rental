<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A generated job completion report (one row per version; older versions are kept). */
class JobReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['missing' => 'array', 'is_current' => 'boolean', 'version' => 'integer', 'bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(fn (JobReport $r) => $r->forceFill(['number' => sprintf('JR-%05d', $r->id)])->saveQuietly());
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function url(bool $download = false): string
    {
        return route('job-report', ['report' => $this->id] + ($download ? ['download' => 1] : []));
    }

    public function isComplete(): bool
    {
        return empty($this->missing);
    }
}
