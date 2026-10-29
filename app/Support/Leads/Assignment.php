<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Cache;

/**
 * Who gets a new lead (brief 28 Oct-2, 15.3). Business settings decide:
 *  - manual (default): nobody; managers assign from the inbox;
 *  - round_robin: the next person in the chosen group (the one given a lead longest ago);
 *  - by_service: the person set for that service, else the round-robin group.
 * A lead that already has someone is never changed.
 */
class Assignment
{
    public const MODES = [
        'manual' => 'Manual (a manager assigns)',
        'round_robin' => 'Round robin in a group',
        'by_service' => 'By service (car, yacht…)',
    ];

    public static function auto(Lead $lead, ?int $preferred = null): ?User
    {
        if ($lead->assigned_to) {
            return null;
        }

        [$user, $how] = self::pick($lead, $preferred);
        if (! $user) {
            return null;
        }

        $lead->forceFill(['assigned_to' => $user->id])->save();
        $lead->activities()->create(['type' => \App\Enums\ActivityType::Note, 'user_id' => null, 'body' => 'Given to '.$user->name.' automatically ('.$how.').']);

        return $user;
    }

    /** @return array{0: ?User, 1: string} */
    public static function pick(Lead $lead, ?int $preferred = null): array
    {
        if ($preferred && ($u = self::eligible()->find($preferred))) {
            return [$u, 'form owner'];
        }

        $mode = (string) Setting::read('lead_assignment', 'manual');
        if ($mode === 'by_service') {
            $service = $lead->service_type?->value;
            $map = collect((array) Setting::read('lead_assignment_by_service', []))->firstWhere('service', $service);
            if ($map && ($u = self::eligible()->find((int) ($map['user_id'] ?? 0)))) {
                return [$u, 'by service'];
            }
        }
        if (in_array($mode, ['round_robin', 'by_service'], true)) {
            $u = self::nextInGroup();

            return [$u, 'round robin'];
        }

        return [null, 'manual'];
    }

    /** Active staff who may work leads (drivers and customers never). */
    public static function eligible()
    {
        $roles = array_values(array_unique([...Access::rolesFor('manage-leads'), 'Super Admin']));

        return User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('name', $roles));
    }

    /** The person in the group who was given a lead the longest time ago (never given one = first). */
    public static function nextInGroup(): ?User
    {
        $ids = array_map('intval', (array) Setting::read('lead_assignment_people', []));
        if (! $ids) {
            return null;
        }

        return Cache::lock('lead-round-robin', 10)->block(5, function () use ($ids) {
            return self::eligible()->whereIn('users.id', $ids)
                ->addSelect(['last_lead_at' => Lead::query()->withTrashed()->selectRaw('MAX(assigned_at)')->whereColumn('assigned_to', 'users.id')])
                ->orderByRaw('last_lead_at IS NOT NULL')->orderBy('last_lead_at')->orderBy('users.id')
                ->first();
        });
    }
}
