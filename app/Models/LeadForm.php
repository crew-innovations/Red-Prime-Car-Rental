<?php

namespace App\Models;

use App\Enums\ServiceType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A shareable form that makes leads (secure link, optional expiry and answer limit). */
class LeadForm extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    public const TYPES = [
        'text' => 'Short text', 'textarea' => 'Long text', 'email' => 'Email', 'phone' => 'Phone',
        'date' => 'Date', 'number' => 'Number', 'select' => 'Choose one', 'checkbox' => 'Tick box',
    ];

    protected function casts(): array
    {
        return ['fields' => 'array', 'is_active' => 'boolean', 'expires_at' => 'datetime', 'service_type' => ServiceType::class,
            'max_submissions' => 'integer', 'submissions' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (LeadForm $f) {
            $f->token ??= Str::random(40);
            $f->created_by ??= auth()->id();
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    public function url(): string
    {
        return route('forms.show', $this->token);
    }

    /** Null = open; otherwise why it is closed. */
    public function closedReason(): ?string
    {
        return match (true) {
            ! $this->is_active => 'This form is closed.',
            $this->expires_at && $this->expires_at->isPast() => 'This form has expired.',
            $this->max_submissions && $this->submissions >= $this->max_submissions => 'This form is full.',
            default => null,
        };
    }

    /** Extra questions, cleaned (keys unique, known types). */
    public function questions(): array
    {
        $seen = [];

        return collect((array) $this->fields)->filter(fn ($f) => filled($f['label'] ?? null))->map(function ($f) use (&$seen) {
            $key = Str::slug((string) ($f['key'] ?? '') ?: (string) $f['label'], '_') ?: 'q';
            while (in_array($key, $seen, true) || in_array($key, ['name', 'phone', 'email', 'website', '_token'], true)) {
                $key .= '_'.(count($seen) + 1);
            }
            $seen[] = $key;

            return ['key' => $key, 'label' => trim((string) $f['label']), 'type' => array_key_exists($f['type'] ?? '', self::TYPES) ? $f['type'] : 'text',
                'required' => (bool) ($f['required'] ?? false), 'options' => array_values(array_filter(array_map('trim', (array) ($f['options'] ?? []))))];
        })->values()->all();
    }
}
