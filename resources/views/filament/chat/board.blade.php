{{-- Shared thread list + conversation. $this uses App\Filament\Concerns\ChatPage. --}}
@php
    $threads = $this->threads();
    $current = $this->current();
    $staff = $this->isStaff();
@endphp
<div class="rp-chat {{ $current ? 'has-thread' : '' }}" wire:poll.15s>
    <aside class="rp-chat-list">
        <div class="rp-chat-tools">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search name or booking" class="rp-chat-search" aria-label="Search conversations">
            {{ $start ?? '' }}
        </div>
        @forelse ($threads as $t)
            @php($unread = $this->unreadIn($t))
            <button type="button" wire:click="open({{ $t->id }})" class="rp-chat-item {{ $current?->id === $t->id ? 'is-active' : '' }}">
                <span class="rp-chat-item-top">
                    <strong>{{ $staff ? $t->title() : ($t->booking ? 'Booking '.$t->booking->reference : 'General question') }}</strong>
                    @if ($unread)<span class="rp-chat-badge">{{ $unread }}</span>@endif
                </span>
                <span class="rp-chat-item-sub">
                    @if ($staff)<span class="rp-chat-kind is-{{ $t->kind }} {{ $t->channel ? 'is-'.$t->channel : '' }}">{{ $t->isSocial() ? $t->channelLabel() : ($t->kind === 'driver' ? 'Driver' : 'Customer') }}</span>@endif
                    {{ $t->latestMessage && (! $t->latestMessage->internal || $staff) ? \Illuminate\Support\Str::limit($t->latestMessage->body, 60) : 'No messages yet' }}
                </span>
                <span class="rp-chat-item-time">{{ $t->last_message_at?->diffForHumans() }}</span>
            </button>
        @empty
            <p class="rp-chat-empty">No conversations yet.</p>
        @endforelse
    </aside>

    <section class="rp-chat-thread">
        @if (! $current)
            <div class="rp-chat-placeholder">Choose a conversation.</div>
        @else
            <header class="rp-chat-head">
                <button type="button" class="rp-chat-back" wire:click="closeThread" aria-label="Back to conversations">‹</button>
                <div>
                    <strong>{{ $staff ? $current->title() : ($current->booking ? 'Booking '.$current->booking->reference : 'General question') }}</strong>
                    @if ($staff && $current->isSocial())
                        <span class="rp-chat-kind is-social is-{{ $current->channel }}">{{ $current->channelLabel() }}</span>
                        @if ($current->lead_id)<a class="rp-chat-link" href="{{ \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $current->lead_id]) }}">Open lead</a>@endif
                    @endif
                    @if ($staff && $current->booking)
                        <a class="rp-chat-link" href="{{ \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $current->booking_id]) }}">Open booking</a>
                    @endif
                </div>
            </header>
            <div class="rp-chat-messages" x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">
                @forelse ($this->messagesOf($current) as $m)
                    @php($mine = (int) $m->user_id === (int) auth()->id())
                    <div class="rp-chat-msg {{ $mine ? 'is-mine' : '' }} {{ $m->internal ? 'is-internal' : '' }}">
                        <div class="rp-chat-meta">
                            {{ $m->author === 'staff' ? ($staff ? ($m->user?->name ?? 'Team') : 'RED PRIME RENTALS') : ($m->user?->name ?? ($current->isSocial() ? $current->title() : ucfirst($m->author))) }}
                            · {{ $m->created_at->format('d M, H:i') }}
                            @if ($m->internal) · <b>Team only</b>@endif
                        </div>
                        <div class="rp-chat-body">{{ $m->body }}@if ($m->attachment_url) <a href="{{ $m->attachment_url }}" target="_blank" rel="noopener noreferrer" class="rp-chat-link">Open attachment</a>@endif</div>
                    </div>
                @empty
                    <p class="rp-chat-empty">No messages yet. Write the first one below.</p>
                @endforelse
            </div>
            @if ($staff && $current->isSocial() && ($why = \App\Support\Chat\Social::blocked($current)))
                <p class="rp-chat-blocked">{{ $why }} You can still save an internal note.</p>
            @elseif ($staff && $current->isSocial())
                <p class="rp-chat-via">Your reply is sent to the customer on {{ $current->channelLabel() }}.</p>
                @if ($links = \App\Support\Chat\Social::links($current))
                    <div class="rp-chat-links"><span>Send:</span>
                        @foreach ($links as $key => $l)<button type="button" wire:click="useLink('{{ $key }}')" class="rp-chat-chip">{{ $l['label'] }}</button>@endforeach
                    </div>
                @endif
            @endif
            <form wire:submit="send" class="rp-chat-compose {{ $internal ? 'is-internal' : '' }}">
                <textarea wire:model="body" rows="2" maxlength="2000" placeholder="{{ $internal ? 'Internal note: only the team sees this' : 'Write a message' }}" aria-label="Message"></textarea>
                <div class="rp-chat-compose-bar">
                    @if ($staff)
                        <label class="rp-chat-toggle"><input type="checkbox" wire:model.live="internal"> Internal note (team only)</label>
                    @else
                        <span class="rp-chat-hint">{{ \App\Support\Chat\Chat::role() === 'driver' ? 'The office sees this.' : 'Our team usually answers within working hours.' }}</span>
                    @endif
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">{{ $internal ? 'Save note' : 'Send' }}</x-filament::button>
                </div>
            </form>
        @endif
    </section>
</div>
