<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Every enquiry this customer has made. */
class LeadsRelationManager extends RelationManager
{
    protected static string $relationship = 'leads';

    protected static ?string $title = 'Enquiry history';

    protected static ?string $icon = 'heroicon-o-inbox-stack';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Received')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('service_type')->label('Interest')->badge()
                    ->description(fn (Lead $record): ?string => $record->interestedIn?->name),
                Tables\Columns\TextColumn::make('preferred_date')->label('For')->date('d M Y')->placeholder('-'),
                Tables\Columns\TextColumn::make('leadSource.name')->label('Source')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('assignee.name')->label('Salesperson')->placeholder('-'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['leadSource', 'assignee', 'interestedIn']))
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Lead $record): string => LeadResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('No enquiries linked yet');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
