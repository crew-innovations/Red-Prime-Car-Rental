<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Enums\Pipeline;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Lead extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'pipeline' => Pipeline::class,
            'priority' => LeadPriority::class,
            'end_date' => 'date',
            'budget' => 'decimal:2',
            'last_contacted_at' => 'datetime',
            'service_type' => ServiceType::class,
            'preferred_date' => 'date',
            'converted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Pipeline and stage drive the overall status (Schedule A3).
        static::saving(function (Lead $lead) {
            $lead->pipeline ??= Pipeline::forService($lead->service_type);

            if (! $lead->pipeline->hasStage($lead->stage)) {
                $lead->stage = $lead->pipeline->firstStage();
            }

            $lead->status = $lead->pipeline->statusFor($lead->stage, (bool) $lead->last_contacted_at);

            if ($lead->status === LeadStatus::Converted) {
                $lead->converted_at ??= now();
            }
            if ($lead->status !== LeadStatus::Lost) {
                $lead->lost_reason = null;
            } elseif (blank($lead->lost_reason)) {
                // Lost always needs a reason (Schedule A3), whichever screen it comes from.
                throw \Illuminate\Validation\ValidationException::withMessages(['data.lost_reason' => 'Please give a reason why the lead was lost.']);
            }

            // Ownership (28 Oct, 3.1): the person a lead is given to accepts it. Taking a
            // lead yourself counts as accepted straight away.
            if (! $lead->exists || $lead->isDirty('assigned_to')) {
                if (! $lead->assigned_to) {
                    $lead->assignment_status = null;
                    $lead->assigned_at = null;
                    $lead->accepted_at = null;
                } else {
                    $lead->assigned_at = now();
                    $self = (int) $lead->assigned_to === (int) auth()->id();
                    $lead->assignment_status = $self ? 'accepted' : 'pending';
                    $lead->accepted_at = $self ? now() : null;
                }
            }
        });

        static::saved(function (Lead $lead) {
            if ($lead->wasChanged('assigned_to') || $lead->wasRecentlyCreated) {
                if ($lead->assignment_status === 'pending' && ($agent = $lead->assignee)) {
                    \App\Support\Drivers\Notify::person($agent, 'New lead for you: '.$lead->name, 'Open it and press "Accept lead" to take it.', \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]));
                }
            }
        });

        // A won deal always has a customer profile.
        static::saved(function (Lead $lead) {
            if ($lead->status === LeadStatus::Converted && ! $lead->customer_id && ! $lead->trashed()) {
                $lead->convertToCustomer();
            }
        });
    }

    public function stageLabel(): string
    {
        return $this->pipeline?->stageLabel($this->stage) ?? (string) $this->stage;
    }

    /** Move forward to a stage; never moves a lead backwards or out of "Lost". */
    public function advanceTo(?string $stage): void
    {
        if (! $stage || ! $this->pipeline?->hasStage($stage) || $this->stage === Pipeline::LOST) {
            return;
        }

        if ($this->pipeline->stageIndex($stage) > $this->pipeline->stageIndex($this->stage)) {
            $this->update(['stage' => $stage]);
        }
    }

    public function acceptAssignment(): void
    {
        abort_unless((int) $this->assigned_to === (int) auth()->id(), 403);
        $this->forceFill(['assignment_status' => 'accepted', 'accepted_at' => now()])->save();
        $this->activities()->create(['type' => \App\Enums\ActivityType::Note, 'body' => 'Accepted the lead.', 'user_id' => auth()->id()]);
    }

    /** The agent hands the lead back; managers are told so it can be given to someone else. */
    public function declineAssignment(string $reason): void
    {
        abort_unless((int) $this->assigned_to === (int) auth()->id(), 403);
        $name = auth()->user()->name;
        $this->activities()->create(['type' => \App\Enums\ActivityType::Note, 'body' => 'Handed back by '.$name.': '.$reason, 'user_id' => auth()->id()]);
        $this->update(['assigned_to' => null]);
        \App\Support\Drivers\Notify::staff('assign-leads', 'Lead handed back: '.$this->name, $name.': '.$reason, \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $this]));
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function interestedIn(): MorphTo
    {
        return $this->morphTo();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest();
    }

    /** Open leads whose follow-up time has passed. */
    public function scopeFollowUpDue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [LeadStatus::Converted, LeadStatus::Lost]);
    }

    public function isConverted(): bool
    {
        return $this->status === LeadStatus::Converted;
    }

    /**
     * Create (or link) the customer profile for this lead. Re-uses an existing
     * customer with the same phone or email so nobody is created twice. The
     * pipeline stage is not changed.
     */
    public function convertToCustomer(): Customer
    {
        return DB::transaction(function () {
            $existing = ($this->phone || $this->whatsapp || $this->email)
                ? Customer::query()->where(function ($q) {
                    foreach (array_filter([$this->phone, $this->whatsapp]) as $number) {
                        $q->orWhere(fn ($q) => \App\Support\Phone::whereMatches($q, ['phone', 'whatsapp'], $number));
                    }
                    if ($this->email) {
                        $q->orWhere('email', $this->email);
                    }
                })->first()
                : null;

            $customer = $this->customer
                ?? $existing
                ?? Customer::create([
                    'name' => $this->name,
                    'phone' => $this->phone,
                    'whatsapp' => $this->whatsapp ?: $this->phone,
                    'email' => $this->email,
                    'lead_source_id' => $this->lead_source_id,
                    'assigned_to' => $this->assigned_to,
                    'company_name' => $this->company_name,
                    'type' => $this->pipeline === Pipeline::B2b ? CustomerType::Corporate : CustomerType::Individual,
                ]);

            $this->forceFill(['customer_id' => $customer->id])->saveQuietly();

            return $customer;
        });
    }
}
