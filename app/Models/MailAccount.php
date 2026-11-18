<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sending mailbox (4 Nov, 14.1). The SMTP password is encrypted at rest, hidden from
 * arrays and never shown again after saving; it can only be replaced. "Connected" only
 * after a successful test email.
 */
class MailAccount extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    public const ENCRYPTION = ['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'];

    /** Host and port filled in from a preset; the person still types their own username and password. */
    public const PRESETS = [
        'hostinger' => ['label' => 'Hostinger email', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl'],
        'google' => ['label' => 'Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
        'microsoft' => ['label' => 'Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls'],
        'zoho' => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.com', 'port' => 465, 'encryption' => 'ssl'],
        'custom' => ['label' => 'Other (type the details)', 'host' => null, 'port' => null, 'encryption' => null],
    ];

    public const STATUSES = ['untested' => 'Not tested', 'connected' => 'Connected', 'failed' => 'Test failed'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_default' => 'boolean',
            'last_tested_at' => 'datetime',
            'port' => 'integer',
            'per_hour' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MailAccount $a) {
            // Changing where or how it sends needs a new test.
            if ($a->exists && $a->isDirty(['host', 'port', 'encryption', 'username', 'password', 'from_address'])) {
                $a->status = 'untested';
            }
        });
        static::saved(function (MailAccount $a) {
            if ($a->is_default) {
                static::query()->whereKeyNot($a->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function title(): string
    {
        return $this->label.' ('.$this->from_address.')';
    }
}
