<?php

namespace App\Support\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Support\Drivers\Notify;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * One way in for leads from ads and shared forms (brief 28 Oct-2, 15.1–15.2):
 *  - the same platform lead id is never saved twice;
 *  - an enquiry from someone who already has an OPEN lead (same phone or email)
 *    is added to that lead as a note instead of making a duplicate;
 *  - otherwise a new lead, given to someone by the assignment rules.
 */
class LeadIntake
{
    public const CHANNELS = [
        'website' => 'Website', 'meta' => 'Facebook / Instagram', 'tiktok' => 'TikTok', 'snapchat' => 'Snapchat',
        'form' => 'Shared form', 'manual' => 'Added by staff',
    ];

    /**
     * $in keys: name, phone, email, whatsapp, service_type, message, preferred_date, guests,
     * channel, source_slug, source_name, external_id, platform (array), answers (array),
     * utm_source, utm_medium, utm_campaign, assign_to (user id, e.g. the form owner).
     *
     * @return array{0: Lead, 1: string} the lead and what happened: created / merged / duplicate
     */
    public static function receive(array $in): array
    {
        $channel = (string) ($in['channel'] ?? 'manual');
        $externalId = filled($in['external_id'] ?? null) ? mb_substr($channel.':'.$in['external_id'], 0, 191) : null;
        $name = trim((string) ($in['name'] ?? '')) ?: (trim((string) ($in['email'] ?? '')) ?: 'Unknown');
        $answers = collect((array) ($in['answers'] ?? []))->map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v)->filter(fn ($v) => $v !== '')->all();

        return DB::transaction(function () use ($in, $channel, $externalId, $name, $answers) {
            if ($externalId && ($same = Lead::withTrashed()->where('external_id', $externalId)->first())) {
                return [$same, 'duplicate'];
            }

            $open = self::openLeadFor($in['phone'] ?? null, $in['email'] ?? null);
            if ($open) {
                $open->activities()->create(['type' => ActivityType::Note, 'user_id' => null,
                    'body' => mb_substr('New enquiry via '.(self::CHANNELS[$channel] ?? $channel)
                        .(data_get($in, 'platform.form_name') ? ' ('.data_get($in, 'platform.form_name').')' : '').":\n"
                        .collect($answers)->map(fn ($v, $k) => $k.': '.$v)->implode("\n").(filled($in['message'] ?? null) ? "\n".$in['message'] : ''), 0, 5000)]);
                if ($open->assignee) {
                    Notify::person($open->assignee, 'Same person asked again: '.$open->name, 'Via '.(self::CHANNELS[$channel] ?? $channel), \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $open]));
                }

                return [$open, 'merged'];
            }

            $source = LeadSource::firstOrCreate(['slug' => $in['source_slug'] ?? $channel], ['name' => $in['source_name'] ?? (self::CHANNELS[$channel] ?? ucfirst($channel)), 'is_active' => true]);
            $lead = Lead::create([
                'name' => mb_substr($name, 0, 255),
                'phone' => filled($in['phone'] ?? null) ? mb_substr(trim((string) $in['phone']), 0, 40) : null,
                'whatsapp' => filled($in['whatsapp'] ?? null) ? mb_substr(trim((string) $in['whatsapp']), 0, 40) : null,
                'email' => filter_var($in['email'] ?? null, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($in['email'])) : null,
                'lead_source_id' => $source->id,
                'channel' => $channel,
                'service_type' => $in['service_type'] ?? null,
                'message' => filled($in['message'] ?? null) ? mb_substr((string) $in['message'], 0, 5000) : null,
                'preferred_date' => $in['preferred_date'] ?? null,
                'guests' => isset($in['guests']) && (int) $in['guests'] > 0 ? (int) $in['guests'] : null,
                'utm_source' => $in['utm_source'] ?? null,
                'utm_medium' => $in['utm_medium'] ?? null,
                'utm_campaign' => $in['utm_campaign'] ?? data_get($in, 'platform.campaign_name'),
                'external_id' => $externalId,
                'platform' => array_filter((array) ($in['platform'] ?? [])) ?: null,
                'answers' => $answers ?: null,
            ]);

            $who = Assignment::auto($lead, isset($in['assign_to']) ? (int) $in['assign_to'] : null);
            if (! $who) {
                DB::afterCommit(fn () => rescue(fn () => Notify::staff('assign-leads', 'New lead: '.$lead->name, 'From '.(self::CHANNELS[$channel] ?? $channel).'. Nobody has it yet.',
                    \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]), null, 'info')));
            }

            return [$lead, 'created'];
        });
    }

    /** An open lead (not won, not lost) of the same person. */
    public static function openLeadFor(?string $phone, ?string $email): ?Lead
    {
        $key = Phone::key($phone);
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($email)) : null;
        if (! $key && ! $email) {
            return null;
        }

        return Lead::query()
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Lost->value])
            ->where(function ($q) use ($phone, $key, $email) {
                if ($key) {
                    $q->orWhere(fn ($p) => Phone::whereMatches($p, ['phone', 'whatsapp'], $phone));
                }
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->latest('id')->first();
    }

    /** Splits form answers into the contact fields and the rest (labels kept as asked). */
    public static function fromAnswers(array $answers): array
    {
        $out = ['answers' => []];
        $first = $last = null;
        foreach ($answers as $key => $value) {
            $k = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $key));
            $v = is_array($value) ? implode(', ', array_filter(array_map('strval', $value))) : trim((string) $value);
            if ($v === '') {
                continue;
            }
            match (true) {
                in_array($k, ['full_name', 'name', 'fullname'], true) => $out['name'] = $v,
                $k === 'first_name' => $first = $v,
                $k === 'last_name' => $last = $v,
                in_array($k, ['phone_number', 'phone', 'mobile', 'mobile_number', 'phone_no'], true) => $out['phone'] = $v,
                in_array($k, ['whatsapp', 'whatsapp_number'], true) => $out['whatsapp'] = $v,
                in_array($k, ['email', 'email_address', 'work_email'], true) => $out['email'] = $v,
                default => $out['answers'][str_replace('_', ' ', ucfirst((string) $key))] = $v,
            };
        }
        if (empty($out['name']) && ($first || $last)) {
            $out['name'] = trim($first.' '.$last);
        }

        return $out;
    }
}
