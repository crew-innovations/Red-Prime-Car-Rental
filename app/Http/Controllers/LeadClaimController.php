<?php

namespace App\Http\Controllers;

use App\Models\LeadClaimToken;
use App\Support\Leads\LeadBroadcast;
use Illuminate\Http\Request;

/**
 * 5 Nov (S3, B.7): accept a lead from the email or the phone notification without logging in.
 * GET only shows the lead and one button (mail scanners open links on their own, so opening the
 * link must never accept anything). POST accepts, with the agent's own single-use token.
 */
class LeadClaimController extends Controller
{
    public function show(string $token)
    {
        $row = LeadClaimToken::findPlain($token);

        return response()->view('public.lead-claim', $this->state($row, $token) + ['done' => null]);
    }

    public function accept(Request $request, string $token)
    {
        $row = LeadClaimToken::findPlain($token);
        $via = in_array($request->query('via'), ['push', 'email'], true) ? $request->query('via') : 'email';
        $state = $this->state($row, $token);

        if ($state['status'] !== 'open') {
            if ($row?->lead && $row->user) {
                rescue(fn () => \App\Models\AuditLog::create(['user_id' => $row->user_id, 'event' => 'lead_claim', 'outcome' => 'failed',
                    'auditable_type' => 'lead', 'auditable_id' => $row->lead_id,
                    'new_values' => ['channel' => $via, 'result' => $state['status'], 'ip' => $request->ip()], 'note' => 'Accept lead: '.$state['status'].' ('.$via.')']), report: false);
            }

            return $this->answer($request, $state + ['done' => 'refused']);
        }

        [$won, $owner] = LeadBroadcast::claim($row->lead, $row->user, $via, $request->ip());
        if ($won) {
            $row->forceFill(['used_at' => now()])->save();
        }

        return $this->answer($request, $this->state($row->fresh(), $token) + ['done' => $won ? 'won' : 'taken', 'owner' => $owner?->name]);
    }

    /** @return array<string, mixed> */
    protected function state(?LeadClaimToken $row, string $token): array
    {
        $lead = $row?->lead;
        $status = match (true) {
            ! $row || ! $lead || ! $row->user => 'invalid',
            $lead->assigned_to && (int) $lead->assigned_to === (int) $row->user_id => 'yours',
            (bool) $lead->assigned_to => 'taken',
            $lead->trashed() || in_array($lead->status, [\App\Enums\LeadStatus::Converted, \App\Enums\LeadStatus::Lost], true) => 'closed',
            ! $row->usable() => 'expired',
            ! LeadBroadcast::isAgent($row->user) => 'invalid',
            default => 'open',
        };

        return [
            'status' => $status,
            'token' => $token,
            'lead' => $lead,
            'summary' => $lead ? LeadBroadcast::summary($lead) : null,
            'owner' => $lead?->assignee?->name,
            'agent' => $row?->user?->name,
            'crmUrl' => $lead ? \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead], panel: 'admin') : url('/crm'),
        ];
    }

    protected function answer(Request $request, array $data)
    {
        if ($request->expectsJson() || $request->query('via') === 'push') {
            return response()->json(['result' => $data['done'], 'status' => $data['status'], 'owner' => $data['owner'] ?? null, 'url' => $data['crmUrl']],
                $data['done'] === 'won' ? 200 : 409);
        }

        return response()->view('public.lead-claim', $data, $data['done'] === 'won' ? 200 : 409);
    }
}
