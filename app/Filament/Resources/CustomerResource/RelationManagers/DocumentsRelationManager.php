<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\DocumentStatus;
use App\Filament\Support\DocumentActions;
use App\Models\CustomerDocument;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Customer documents with review (Schedule A11). Files are private. */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static ?string $icon = 'heroicon-o-identification';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->documents()->where('status', DocumentStatus::Received)->count();

        return $count ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'info';
    }

    /** Passports, IDs and licences: only people who handle documents see them. */
    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return Access::allows('review-documents');
    }

    public function isReadOnly(): bool
    {
        return ! Access::allows('review-documents');
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema(DocumentActions::uploadForm($this->getOwnerRecord()->getKey()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->formatStateUsing(fn (?string $state): string => \App\Support\Options::documentLabel($state))
                    ->badge()
                    ->color('gray')
                    ->description(fn (CustomerDocument $record): ?string => $record->original_name),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->description(fn (CustomerDocument $record): ?string => $record->review_note),
                Tables\Columns\TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date('d M Y')
                    ->placeholder('-')
                    ->color(fn (CustomerDocument $record): ?string => $record->expires_on?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('uploaded_via')
                    ->label('From')
                    ->formatStateUsing(fn (string $state): string => match ($state) { 'link' => 'Customer link', 'account' => 'Customer account', default => 'Staff' }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->dateTimeTooltip('d M Y, H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                DocumentActions::requestLink(Tables\Actions\Action::class, fn () => $this->getOwnerRecord(), fn () => null),
                Tables\Actions\CreateAction::make()
                    ->label('Upload document')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->modalWidth('2xl')
                    ->mutateFormDataUsing(fn (array $data): array => DocumentActions::staffUploadData($data)),
            ])
            ->actions([
                ...DocumentActions::reviewActions(),
                Tables\Actions\DeleteAction::make()
                    ->iconButton()
                    ->authorize('delete-records'),
            ])
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Send an upload link to the customer, or upload a file you already have.');
    }
}
