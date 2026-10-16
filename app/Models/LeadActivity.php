<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => ActivityType::class];
    }

    protected static function booted(): void
    {
        static::creating(function (LeadActivity $activity) {
            $activity->user_id ??= auth()->id();
        });

        // Any real contact moves a new lead to "Contacted" and stamps the time.
        static::created(function (LeadActivity $activity) {
            if ($activity->type === ActivityType::Note || ! $activity->lead) {
                return;
            }

            $lead = $activity->lead;
            $lead->last_contacted_at = $activity->created_at;

            if ($lead->status === LeadStatus::New) {
                $lead->status = LeadStatus::Contacted;
            }

            $lead->save();
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
