<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\DocumentRequest;
use App\Support\Access;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Upload links sent to the customer; staff can switch a link off at any time. */
class DocumentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'documentRequests';

    protected static ?string $title = 'Upload links';

    protected static ?string $icon = 'heroicon-o-link';

    public function isReadOnly(): bool
    {
        return ! Access::allows('review-documents');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('types')
                    ->label('Asked for')
                    ->formatStateUsing(fn (DocumentRequest $record): string => implode(', ', $record->typeLabels()))
                    ->wrap(),
                Tables\Columns\TextColumn::make('state')
                    ->label('Link')
                    ->badge()
                    ->state(fn (DocumentRequest $record): string => $record->revoked_at ? 'Switched off' : ($record->expires_at->isPast() ? 'Expired' : 'Active'))
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray')
                    ->description(fn (DocumentRequest $record): string => 'Until '.$record->expires_at->format('d M, H:i')),
                Tables\Columns\TextColumn::make('upload_count')->label('Files received'),
                Tables\Columns\TextColumn::make('creator.name')->label('Sent by')->placeholder('-'),
                Tables\Columns\TextColumn::make('created_at')->label('Created')->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Switch off')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('review-documents')
                    ->visible(fn (DocumentRequest $record): bool => $record->isUsable())
                    ->action(fn (DocumentRequest $record) => $record->update(['revoked_at' => now()])),
            ])
            ->emptyStateHeading('No upload links sent');
    }
}
