<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An extra label an Admin adds. It does not replace the record's real status. */
class CustomStatus extends Model
{
    public const TYPES = ['lead', 'booking', 'quotation'];

    public const COLORS = [
        'gray' => 'Grey',
        'info' => 'Blue',
        'success' => 'Green',
        'warning' => 'Amber',
        'danger' => 'Red',
        'primary' => 'Brand',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<int, string> */
    public static function options(string $type): array
    {
        return static::query()
            ->where('record_type', $type)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
