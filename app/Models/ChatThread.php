<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatThread extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_inbound_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** 12 Nov: a conversation from Instagram / Messenger / WhatsApp belongs to a lead. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function isSocial(): bool
    {
        return $this->kind === 'social';
    }

    public function channelLabel(): ?string
    {
        return \App\Support\Chat\Social::CHANNELS[$this->channel] ?? null;
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    public function title(): string
    {
        if ($this->isSocial()) {
            return $this->lead?->name ?: ($this->external_name ?: ($this->channelLabel().' user'));
        }

        $who = $this->kind === 'driver' ? ($this->driver?->name ?? 'Driver') : ($this->customer?->name ?? 'Customer');

        return $who.($this->booking ? ' · '.$this->booking->reference : '').($this->subject ? ' · '.$this->subject : '');
    }
}
