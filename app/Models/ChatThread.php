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
        return ['last_message_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
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
        $who = $this->kind === 'driver' ? ($this->driver?->name ?? 'Driver') : ($this->customer?->name ?? 'Customer');

        return $who.($this->booking ? ' · '.$this->booking->reference : '').($this->subject ? ' · '.$this->subject : '');
    }
}
