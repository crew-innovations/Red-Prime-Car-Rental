<?php

namespace App\Filament\Concerns;

use App\Models\ChatThread;
use App\Support\Chat\Chat;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/** Shared by the team / driver Messages page and the customer's Messages page. */
trait ChatPage
{
    #[Url(as: 'thread')]
    public ?int $threadId = null;

    public string $body = '';

    public bool $internal = false;

    public string $search = '';

    public function threads()
    {
        return Chat::visible()
            ->with(['latestMessage', 'booking', 'customer', 'driver', 'lead'])
            ->when(trim($this->search) !== '', function ($q) {
                $s = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->whereHas('customer', fn ($c) => $c->where('name', 'like', $s))
                    ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', $s))
                    ->orWhereHas('driver', fn ($d) => $d->where('name', 'like', $s))
                    ->orWhere('external_name', 'like', $s)->orWhereHas('lead', fn ($l) => $l->where('name', 'like', $s)->orWhere('phone', 'like', $s)));
            })
            ->orderByRaw('last_message_at IS NULL')->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(60)->get();
    }

    public function current(): ?ChatThread
    {
        if (! $this->threadId) {
            return null;
        }
        $t = Chat::visible()->with(['booking', 'customer', 'driver', 'lead'])->find($this->threadId);
        if ($t) {
            Chat::markRead($t);
        }

        return $t;
    }

    public function messagesOf(ChatThread $t)
    {
        abort_unless(Chat::visible()->whereKey($t->id)->exists(), 404);

        return Chat::messages($t)->limit(300)->get();
    }

    public function open(int $id): void
    {
        $this->threadId = $id;
        $this->body = '';
        $this->internal = false;
    }

    public function closeThread(): void
    {
        $this->threadId = null;
    }

    public function send(): void
    {
        $t = $this->threadId ? Chat::visible()->find($this->threadId) : null;
        abort_unless($t, 404);
        try {
            Chat::post($t, $this->body, $this->internal);
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->warning()->send();

            return;
        }
        $this->body = '';
        $this->internal = false;
    }

    /** 12 Nov (C3): one tap puts a ready message with the customer's link in the box; the agent then presses Send. */
    public function useLink(string $key): void
    {
        $t = $this->threadId ? Chat::visible()->with('lead')->find($this->threadId) : null;
        abort_unless($t && $t->isSocial() && Chat::role() === 'staff', 404);
        $link = \App\Support\Chat\Social::links($t)[$key] ?? null;
        if ($link) {
            $this->internal = false;
            $this->body = $link['text'];
        }
    }

    public function unreadIn(ChatThread $t): int
    {
        return Chat::unread(null, $t);
    }
}
