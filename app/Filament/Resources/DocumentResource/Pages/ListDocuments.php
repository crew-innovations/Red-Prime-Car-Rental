<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\DocumentResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected ?string $subheading = 'New uploads to review, and documents that have expired. To upload or request a document, open the customer.';

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'review' => Tab::make('To review')
                ->icon('heroicon-m-inbox-arrow-down')
                ->badge(fn () => DocumentResource::getNavigationBadge())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DocumentStatus::Received)),
            'expired' => Tab::make('Expired')
                ->icon('heroicon-m-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DocumentStatus::Approved)->whereDate('expires_on', '<', today())),
            'replace' => Tab::make('Waiting for a new upload')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [DocumentStatus::Replacement, DocumentStatus::Rejected])),
        ];
    }
}
