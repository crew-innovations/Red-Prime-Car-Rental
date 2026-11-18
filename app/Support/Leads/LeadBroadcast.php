<?php

namespace App\Support\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadClaimToken;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LeadBroadcastMessage;
use App\Support\Access;
use App\Support\Drivers\Notify;
use App\Support\Notifications\Notifier;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 5 Nov ("Make it simple", Part B): a new lead goes to every active agent; the first one to accept
 * owns it and everything that comes from it.
 *
 *  - open(): a repeat customer's lead goes straight to their agent (setting leads_repeat_to_owner);
 *    otherwise every agent is told (bell with Accept, phone with Accept, email with a big button)
 *    and Admin hears "New lead".
 *  - claim(): one conditional UPDATE (… WHERE assigned_to IS NULL); exactly one request can win.
 *    The others see "Taken by …", Admin hears "accepted by X after N minutes".
 *  - remindWaiting(): after lead_accept_minutes, one more reminder to every agent and "Lead not
 *    accepted" to Admin, who can then assign it by hand.
 * Old leads without an owner are never sent out (decision H-44): Admin assigns them.
 */
class LeadBroadcast
{
    /** Active agents who can take leads (not Admin, not the hidden account). */
    public static function agents(): Collection
    {
        return User::query()->where('is_active', true)->where('is_system', false)->whereNull('customer_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', Access::withLegacy(['Agent'])))
            ->with('roles')->get()
            ->filter(fn (User $u) => Access::rolesOf($u)->diff(['Customer', 'Driver'])->values()->all() === ['Agent'])
            ->values();
    }

    public static function isAgent(?User $user): bool
    {
        return $user !== null && $user->is_active && Access::rolesOf($user)->diff(['Customer', 'Driver'])->values()->all() === ['Agent'];
    }

    /** "New lead · Rolls-Royce Cullinan · 3 days · Ahmed" */
    public static function summary(Lead $lead): string
    {
        $days = $lead->preferred_date && $lead->end_date ? max(1, $lead->preferred_date->diffInDays($lead->end_date)) : null;

        return collect([
            'New lead',
            $lead->interestedIn?->name ?? $lead->service_type?->getLabel(),
            $days ? $days.' '.($days === 1 ? 'day' : 'days') : ($lead->preferred_date?->format('d M') ?: null),
            $lead->name,
        ])->filter()->implode(' · ');
    }

    /**
     * Sends a new lead out (or straight to a repeat customer's agent).
     * Returns the agent it went to straight away, or null when it waits for someone to accept.
     */
    public static function open(Lead $lead): ?User
    {
        $lead->refresh();
        if ($lead->assigned_to || $lead->broadcast_at || in_array($lead->status, [LeadStatus::Converted, LeadStatus::Lost], true)) {
            return null;
        }

        if (Setting::read('leads_repeat_to_owner', true) && ($owner = self::repeatOwner($lead))) {
            Lead::query()->whereKey($lead->id)->whereNull('assigned_to')->update([
                'assigned_to' => $owner->id, 'assignment_status' => 'accepted', 'assigned_at' => now(), 'accepted_at' => now(),
                'claim_channel' => 'repeat', 'updated_at' => now(),
            ]);
            $lead->refresh();
            $lead->activities()->create(['type' => ActivityType::Note, 'user_id' => null, 'body' => 'Repeat customer: given straight to '.$owner->name.'.']);
            $url = \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]);
            DB::afterCommit(function () use ($owner, $lead, $url) {
                Notify::person($owner, 'Repeat customer: '.self::summary($lead), 'This customer is yours, so the lead came straight to you.', $url, 'success', 'lead_new', 'lead_new:'.$lead->id);
                Notify::staff('assign-leads', 'New lead (repeat customer): '.$lead->name, 'Given straight to '.$owner->name.'.', $url, null, 'info', 'lead_new', 'lead_new:'.$lead->id);
            });

            return $owner;
        }

        $agents = self::agents();
        $lead->forceFill(['broadcast_at' => now()])->saveQuietly();
        $lead->activities()->create(['type' => ActivityType::Note, 'user_id' => null,
            'body' => 'Sent to '.$agents->count().' '.($agents->count() === 1 ? 'agent' : 'agents').'. The first to accept owns it.']);

        DB::afterCommit(function () use ($lead, $agents) {
            self::sendToAgents($lead, $agents, false);
            $url = \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]);
            Notify::staff('assign-leads', self::summary($lead), $agents->isEmpty()
                ? 'No active agent to send it to. Please assign it.'
                : 'Sent to '.$agents->count().' agents. Waiting for one to accept.', $url, null, 'info', 'lead_new', 'lead_new:'.$lead->id);
        });

        return null;
    }

    /** Each agent gets a personal accept link (email / phone) and a bell notice with an Accept button. */
    protected static function sendToAgents(Lead $lead, Collection $agents, bool $reminder): void
    {
        foreach ($agents as $agent) {
            try {
                $token = LeadClaimToken::issue($lead, $agent);
                $note = new LeadBroadcastMessage($lead, $token, $reminder);
                Notifier::send($agent, $note, $lead, 'lead_broadcast:'.$lead->id.':'.$agent->id.($reminder ? ':reminder' : ''));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The first agent to accept owns the lead. Safe when many accept in the same second: the
     * UPDATE only changes a row that still has no owner, and only one request can change it.
     *
     * @return array{0: bool, 1: ?User} won?, and who owns the lead now
     */
    public static function claim(Lead $lead, User $agent, string $channel = 'crm', ?string $ip = null): array
    {
        if (! self::isAgent($agent)) {
            self::log($lead, $agent, $channel, 'not an agent', $ip);

            return [false, $lead->fresh()?->assignee];
        }

        $changed = DB::transaction(fn () => Lead::query()
            ->whereKey($lead->id)
            ->whereNull('assigned_to')
            ->whereNull('deleted_at')
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Lost->value])
            ->update([
                'assigned_to' => $agent->id,
                'assignment_status' => 'accepted',
                'assigned_at' => now(),
                'accepted_at' => now(),
                'claim_channel' => mb_substr($channel, 0, 20),
                'updated_at' => now(),
            ]));

        $lead = $lead->fresh();
        if ($changed !== 1) {
            self::log($lead, $agent, $channel, 'taken', $ip);

            return [false, $lead?->assignee];
        }

        self::log($lead, $agent, $channel, 'won', $ip);
        try {
            self::afterClaim($lead, $agent, $channel); // the lead is theirs already; a failed notice never undoes that
        } catch (\Throwable $e) {
            report($e);
        }

        return [true, $agent];
    }

    protected static function afterClaim(Lead $lead, User $agent, string $channel): void
    {
        $minutes = $lead->broadcast_at ? (int) $lead->broadcast_at->diffInMinutes(now()) : null;
        $after = $minutes === null ? '' : ' after '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
        $lead->activities()->create(['type' => ActivityType::Note, 'user_id' => $agent->id,
            'body' => 'Accepted by '.$agent->name.$after.' ('.self::channelLabel($channel).').']);

        // The customer (if the lead already has one) belongs to the agent too, unless someone else has them.
        if ($lead->customer_id) {
            Customer::query()->whereKey($lead->customer_id)->whereNull('assigned_to')->update(['assigned_to' => $agent->id]);
        }
        LeadClaimToken::query()->where('lead_id', $lead->id)->whereNull('used_at')->update(['used_at' => now()]);

        self::markTaken($lead, $agent);

        $url = \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]);
        DB::afterCommit(function () use ($lead, $agent, $after, $url) {
            Notify::staff('assign-leads', 'Lead accepted by '.$agent->name.$after, self::summary($lead), $url, null, 'success', 'lead_claimed', 'lead_claimed:'.$lead->id);
        });
    }

    /** Everyone else's bell notice turns into "Taken by …" (no Accept button) and their phone notice is replaced. */
    public static function markTaken(Lead $lead, User $owner): void
    {
        $all = DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where(fn ($q) => $q->where('data', 'like', '%"lead_claim":'.$lead->id.',%')->orWhere('data', 'like', '%"lead_claim":'.$lead->id.'}%'))
            ->get(['id', 'notifiable_id', 'data']);
        $url = \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]);

        foreach ($all as $row) {
            $data = json_decode($row->data, true) ?: [];
            $mine = (int) $row->notifiable_id === (int) $owner->id;
            $data['title'] = $mine ? 'You accepted: '.$lead->name : 'Taken by '.$owner->name.': '.$lead->name;
            $data['body'] = self::summary($lead);
            $data['actions'] = $mine ? [\Filament\Notifications\Actions\Action::make('open')->label('Open')->url($url)->markAsRead()->toArray()] : [];
            $data['iconColor'] = $mine ? 'success' : 'gray';
            $data['viewData'] = ['lead_taken' => $lead->id, 'taken_by' => $owner->name, 'event' => 'lead_broadcast'];
            DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data), 'read_at' => $mine ? null : now(), 'updated_at' => now()]);
        }

        $others = $all->reject(fn ($r) => (int) $r->notifiable_id === (int) $owner->id)->pluck('notifiable_id')->unique()->all();
        if ($others) {
            DB::afterCommit(function () use ($others, $lead, $owner) {
                foreach (User::query()->whereIn('id', $others)->get() as $u) {
                    try {
                        $u->notifyNow(new \App\Notifications\LeadTakenPush($lead, $owner->name));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
        }
    }

    /** Leads nobody accepted within lead_accept_minutes: one more reminder to all agents, and Admin is told. */
    public static function remindWaiting(): int
    {
        $minutes = (int) Setting::read('lead_accept_minutes', 60);
        if ($minutes <= 0) {
            return 0;
        }
        $count = 0;
        Lead::query()->whereNull('assigned_to')->whereNotNull('broadcast_at')->whereNull('broadcast_reminded_at')
            ->where('broadcast_at', '<=', now()->subMinutes($minutes))
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Lost->value])
            ->each(function (Lead $lead) use (&$count, $minutes) {
                $lead->forceFill(['broadcast_reminded_at' => now()])->saveQuietly();
                self::sendToAgents($lead, self::agents(), true);
                Notify::staff('assign-leads', 'Lead not accepted: '.$lead->name, 'No agent accepted it in '.$minutes.' minutes. Please assign it.',
                    \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]), null, 'warning', 'lead_unaccepted', 'lead_unaccepted:'.$lead->id);
                $count++;
            });

        return $count;
    }

    /** The agent of an existing customer with the same phone or email (only an active agent). */
    public static function repeatOwner(Lead $lead): ?User
    {
        $numbers = array_filter([$lead->phone, $lead->whatsapp]);
        $email = filter_var($lead->email, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($lead->email)) : null;
        $customer = $lead->customer_id ? Customer::find($lead->customer_id) : null;
        if (! $customer && ($numbers || $email)) {
            $customer = Customer::query()->where(function ($q) use ($numbers, $email) {
                foreach ($numbers as $n) {
                    if (Phone::key($n)) {
                        $q->orWhere(fn ($p) => Phone::whereMatches($p, ['phone', 'whatsapp'], $n));
                    }
                }
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })->first();
        }
        if (! $customer) {
            return null;
        }
        $ownerId = $customer->assigned_to
            ?: Lead::query()->where('customer_id', $customer->id)->whereKeyNot($lead->id)->whereNotNull('assigned_to')->latest('id')->value('assigned_to');
        $owner = $ownerId ? User::find($ownerId) : null;

        return self::isAgent($owner) ? $owner : null;
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'email' => 'from the email',
            'push' => 'from the phone notification',
            'bell' => 'from the bell',
            'repeat' => 'repeat customer',
            default => 'in the CRM',
        };
    }

    protected static function log(?Lead $lead, User $agent, string $channel, string $result, ?string $ip): void
    {
        rescue(fn () => AuditLog::create([
            'user_id' => $agent->id,
            'event' => 'lead_claim',
            'outcome' => $result === 'won' ? 'success' : 'failed',
            'auditable_type' => 'lead',
            'auditable_id' => $lead?->id,
            'new_values' => ['channel' => $channel, 'result' => $result, 'ip' => $ip ?? request()?->ip()],
            'note' => mb_substr('Accept lead: '.$result.' ('.$channel.')', 0, 255),
        ]), report: false);
    }
}
