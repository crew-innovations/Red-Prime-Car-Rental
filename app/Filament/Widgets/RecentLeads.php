<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Widgets\Widget;

class RecentLeads extends Widget
{
    public static function canView(): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    protected static string $view = 'filament.widgets.recent-leads';

    protected static ?int $sort = 7;

    protected function getViewData(): array
    {
        $leads = Lead::query()
            ->with(['leadSource', 'interestedIn'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (Lead $lead) => [
                'initials' => str($lead->name)->explode(' ')->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''),
                'name' => $lead->name,
                'interest' => $lead->interestedIn?->name ?? $lead->service_type?->getLabel() ?? 'General enquiry',
                'source' => $lead->leadSource?->name,
                'status' => $lead->status?->getLabel(),
                'color' => $lead->status?->getColor() ?? 'gray',
                'time' => $lead->created_at?->diffForHumans(),
                'url' => LeadResource::getUrl('edit', ['record' => $lead]),
            ]);

        return ['leads' => $leads, 'allUrl' => LeadResource::getUrl('index')];
    }
}
