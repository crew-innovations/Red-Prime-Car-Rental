<?php

namespace App\Support\Reports;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Support\Access;
use App\Support\Leads\LeadIntake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leads report (brief 28 Oct-2, Phase 20): leads received in a period, grouped
 * by where they came in, campaign, source or person, with won / lost / open and
 * the conversion (won ÷ leads). Agents only ever see their own leads.
 */
class LeadReport
{
    public const VIEWS = ['channel' => 'Came in by', 'source' => 'Source', 'campaign' => 'Campaign', 'agent' => 'Person', 'service' => 'Service'];

    public function __construct(public Carbon $from, public Carbon $to) {}

    public function leads(): Collection
    {
        return Access::scopeOwn(Lead::query(), 'lead')
            ->with(['leadSource', 'assignee'])
            ->whereBetween('created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->get(['id', 'name', 'status', 'channel', 'lead_source_id', 'assigned_to', 'service_type', 'platform', 'utm_campaign', 'assignment_status', 'converted_at', 'created_at']);
    }

    public function summary(): array
    {
        $l = $this->leads();

        return $this->counts($l) + ['unassigned' => $l->whereNull('assigned_to')->count()];
    }

    public function rows(string $by): array
    {
        $leads = $this->leads();
        $groups = $leads->groupBy(fn (Lead $x) => match ($by) {
            'source' => (string) ($x->leadSource?->name ?? 'No source'),
            'campaign' => (string) (data_get($x->platform, 'campaign_name') ?: $x->utm_campaign ?: '—'),
            'agent' => (string) ($x->assignee?->name ?? 'Not assigned'),
            'service' => (string) ($x->service_type?->getLabel() ?? 'Not set'),
            default => (string) (LeadIntake::CHANNELS[$x->channel] ?? ($x->channel ?: 'Not recorded')),
        });

        return $groups->map(fn (Collection $g, $label) => ['label' => $label] + $this->counts($g))
            ->sortByDesc('leads')->values()->all();
    }

    protected function counts(Collection $g): array
    {
        $n = $g->count();
        $won = $g->filter(fn (Lead $x) => $x->status === LeadStatus::Converted)->count();
        $lost = $g->filter(fn (Lead $x) => $x->status === LeadStatus::Lost)->count();

        return [
            'leads' => $n, 'won' => $won, 'lost' => $lost, 'open' => $n - $won - $lost,
            'conversion' => $n ? round($won * 100 / $n, 1) : null,
        ];
    }
}
