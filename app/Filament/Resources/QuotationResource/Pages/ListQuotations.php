<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Enums\QuotationStatus;
use App\Filament\Resources\QuotationResource;
use App\Models\Quotation;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    public function mount(): void
    {
        // Quotes past "valid until" become Expired (kept, never deleted).
        Quotation::expireOverdue();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New quotation'),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (array $statuses) => Quotation::whereIn('status', $statuses)->count() ?: null;
        $open = [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation];

        return [
            'open' => Tab::make('Open')
                ->badge($count($open))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $open)),
            'accepted' => Tab::make('Accepted · to book')
                ->badge(Quotation::where('status', QuotationStatus::Accepted)->whereNull('booking_id')->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QuotationStatus::Accepted)),
            'closed' => Tab::make('Declined / expired')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [QuotationStatus::Rejected, QuotationStatus::Expired])),
            'all' => Tab::make('All'),
        ];
    }
}
