<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Every email the CRM sends (4 Nov, 14.4). Bodies are cleared after the retention period. */
class EmailLog extends Model
{
    protected $table = 'email_log';

    protected $guarded = ['id'];

    public const STATUSES = ['held' => 'Waiting (hourly limit)', 'queued' => 'Queued', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'cancelled' => 'Not sent'];

    public const COLORS = ['held' => 'gray', 'queued' => 'gray', 'sending' => 'info', 'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'warning'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'send_after' => 'datetime', 'attempts' => 'integer', 'payload' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id')->withTrashed();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
