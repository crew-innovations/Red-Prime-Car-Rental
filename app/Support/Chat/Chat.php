<?php

namespace App\Support\Chat;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Notifications\CrmMessage;
use App\Support\Access;
use App\Support\CustomerArea;
use App\Support\Drivers\Notify;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Messages (brief 28 Oct-2, Phase 17). Who sees what:
 *  - customers: only their own customer threads, never internal notes;
 *  - drivers: only their own driver threads, never internal notes;
 *  - staff: threads of bookings / customers they can open (agents: their own), with internal notes.
 * Nothing is sent outside the CRM (no WhatsApp / SMS / email to customers from here).
 */
class Chat
{
    public static function role(?User $user = null): ?string
    {
        $user ??= auth()->user();

        return match (true) {
            ! $user => null,
            ! $user->hasCrmRole() => $user->customer_id ? 'customer' : null,
            $user->isDriverOnly() => 'driver',
            default => 'staff',
        };
    }

    /** Threads this person may open. */
    public static function visible(?User $user = null): Builder
    {
        $user ??= auth()->user();
        $q = ChatThread::query();

        return match (self::role($user)) {
            'customer' => $q->where('kind', 'customer')->where('customer_id', $user->customer_id),
            'driver' => $q->where('kind', 'driver')->where('driver_id', $user->id),
            // 12 Nov: a social conversation is seen by the agent who has its lead (and by Admin).
            'staff' => Access::agentScoped()
                ? $q->where(fn ($w) => $w->where(fn ($c) => $c->where('kind', '<>', 'social')->where(fn ($x) => $x->whereIn('booking_id', BookingResource::getEloquentQuery()->select('bookings.id'))
                    ->orWhere(fn ($g) => $g->whereNull('booking_id')->whereIn('customer_id', Access::scopeOwn(\App\Models\Customer::query(), 'customer')->select('customers.id')))))
                    ->orWhere(fn ($so) => $so->where('kind', 'social')->whereIn('lead_id', \App\Models\Lead::query()->where('assigned_to', $user->id)->select('id'))))
                : $q->when($user->isDriverOnly(), fn ($d) => $d->where('kind', '<>', 'social')),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /** Messages of a thread this person may read. */
    public static function messages(ChatThread $thread, ?User $user = null)
    {
        return $thread->messages()->with('user')->when(self::role($user) !== 'staff', fn ($q) => $q->where('internal', false));
    }

    public static function customerThread(Booking $b): ChatThread
    {
        return ChatThread::firstOrCreate(['kind' => 'customer', 'booking_id' => $b->id], ['customer_id' => $b->customer_id]);
    }

    public static function driverThread(Booking $b, User $driver): ChatThread
    {
        return ChatThread::firstOrCreate(['kind' => 'driver', 'booking_id' => $b->id, 'driver_id' => $driver->id], ['customer_id' => $b->customer_id]);
    }

    /** A customer writes about one of their bookings (or in general when none). */
    public static function threadForCustomer(?Booking $b = null): ChatThread
    {
        $id = CustomerArea::customerId();
        if ($b) {
            abort_unless(CustomerArea::bookings()->whereKey($b->id)->exists(), 404);

            return self::customerThread($b);
        }

        return ChatThread::firstOrCreate(['kind' => 'customer', 'booking_id' => null, 'customer_id' => $id]);
    }

    public static function post(ChatThread $thread, string $body, bool $internal = false, ?User $user = null): ChatMessage
    {
        $user ??= auth()->user();
        abort_unless($user && self::visible($user)->whereKey($thread->id)->exists(), 403);
        $role = self::role($user);
        $body = trim(preg_replace("/\r\n?/", "\n", $body));
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message.']);
        }
        if (mb_strlen($body) > 2000) {
            throw ValidationException::withMessages(['body' => 'Keep it under 2,000 characters.']);
        }
        $internal = $internal && $role === 'staff'; // only the team writes internal notes

        // 12 Nov: in a conversation from Instagram / Messenger / WhatsApp the reply goes out on that
        // channel first; if Meta refuses it, nothing is saved and the agent is told why.
        $externalId = null;
        if ($thread->isSocial() && ! $internal) {
            abort_unless($role === 'staff', 403);
            $externalId = Social::send($thread, $body);
            // It is already with the customer: an id we somehow hold already must never stop it being saved.
            if ($externalId && ChatMessage::query()->where('external_id', $externalId)->exists()) {
                $externalId = null;
            }
        }

        $message = DB::transaction(function () use ($thread, $body, $internal, $user, $role, $externalId) {
            $m = $thread->messages()->create(['user_id' => $user->id, 'author' => $role, 'body' => $body, 'internal' => $internal, 'external_id' => $externalId]);
            $thread->forceFill(['last_message_at' => now()])->save();
            self::markRead($thread, $user, $m->id);

            return $m;
        });

        if (! $thread->isSocial()) { // the customer of a social conversation is reached on their channel, not in the CRM
            rescue(fn () => self::notify($thread, $message, $user));
        }

        return $message;
    }

    public static function markRead(ChatThread $thread, ?User $user = null, ?int $upTo = null): void
    {
        $user ??= auth()->user();
        if (! $user) {
            return;
        }
        $upTo ??= (int) $thread->messages()->max('id');
        DB::table('chat_reads')->upsert(
            [['chat_thread_id' => $thread->id, 'user_id' => $user->id, 'last_message_id' => $upTo, 'created_at' => now(), 'updated_at' => now()]],
            ['chat_thread_id', 'user_id'], ['last_message_id', 'updated_at']
        );
    }

    /** Unread messages for this person (others' messages they may see, after their last read). */
    public static function unread(?User $user = null, ?ChatThread $thread = null): int
    {
        $user ??= auth()->user();
        if (! $user || ! self::role($user)) {
            return 0;
        }

        return ChatMessage::query()
            ->whereIn('chat_thread_id', $thread ? [$thread->id] : self::visible($user)->select('id'))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '<>', $user->id))
            ->when(self::role($user) !== 'staff', fn ($q) => $q->where('internal', false))
            ->whereRaw('id > COALESCE((SELECT last_message_id FROM chat_reads r WHERE r.chat_thread_id = chat_messages.chat_thread_id AND r.user_id = ?), 0)', [$user->id])
            ->count();
    }

    /** In-app notice to the other side (never internal notes to customers / drivers). */
    protected static function notify(ChatThread $thread, ChatMessage $m, User $from): void
    {
        $preview = mb_strimwidth($m->body, 0, 120, '…');
        $thread->loadMissing('booking.assignee');

        if ($m->author !== 'staff') {
            $url = \App\Filament\Pages\Messages::getUrl(['thread' => $thread->id], panel: 'admin');
            $title = 'Message from '.($m->author === 'driver' ? 'driver ' : '').$from->name.($thread->booking ? ' · '.$thread->booking->reference : '');
            $owner = $thread->booking?->assignee;
            if ($owner && $owner->is_active) {
                Notify::person($owner, $title, $preview, $url, event: 'message');
            } else {
                Notify::staff($m->author === 'driver' ? 'manage-drivers' : 'manage-bookings', $title, $preview, $url, null, 'info', event: 'message');
            }

            return;
        }
        if ($m->internal) {
            return;
        }

        $people = $thread->kind === 'driver'
            ? collect([$thread->driver])->filter()
            : User::query()->where('customer_id', $thread->customer_id)->where('is_active', true)->get()->reject(fn (User $u) => $u->hasCrmRole());
        foreach ($people as $person) {
            $url = $thread->kind === 'driver'
                ? \App\Filament\Pages\Messages::getUrl(['thread' => $thread->id], panel: 'admin')
                : \App\Filament\Account\Pages\Messages::getUrl(['thread' => $thread->id], panel: 'account');
            rescue(fn () => $person->notify(new CrmMessage('New message from RED PRIME RENTALS', $preview, $url, false, 'heroicon-o-chat-bubble-left-right', 'info')));
        }
    }
}
