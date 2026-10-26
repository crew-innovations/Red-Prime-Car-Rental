<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Models\DriverDocument;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Passport, ID, visa, licence and other papers of a driver. Files stay on the
 * private disk and open through short signed links for permitted users only.
 * "Replace" adds a new copy and keeps the old one as history.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'driverDocuments';

    protected static ?string $title = 'Documents';

    protected static ?string $icon = 'heroicon-o-document-text';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::allows('manage-drivers');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $n = $ownerRecord->driverDocuments()->current()->whereNotNull('expires_on')->where('expires_on', '<=', today()->addDays(DriverDocument::warnDays()))->count();

        return $n ? (string) $n : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function fields(bool $withFile = true, ?string $type = null): array
    {
        return array_values(array_filter([
            Forms\Components\Select::make('type')
                ->options(DriverDocument::TYPES)
                ->default($type)
                ->native(false)
                ->required()
                ->live()
                ->disabled($type !== null)
                ->dehydrated(),
            Forms\Components\TextInput::make('label')
                ->label('Document name')
                ->maxLength(120)
                ->required(fn (Get $get): bool => $get('type') === 'other')
                ->visible(fn (Get $get): bool => in_array($get('type'), ['other', 'permit'], true)),
            Forms\Components\TextInput::make('number')
                ->label(fn (Get $get): string => match ($get('type')) {
                    'passport' => 'Passport number',
                    'emirates_id' => 'ID number',
                    'visa' => 'Visa / residency number',
                    'licence' => 'Licence number',
                    default => 'Number',
                })
                ->maxLength(60)
                ->helperText('Stored encrypted. Lists only show the last 4 characters.'),
            Forms\Components\TextInput::make('issued_by')
                ->label(fn (Get $get): string => in_array($get('type'), ['licence', 'permit'], true) ? 'Issuing authority / country' : 'Issuing country')
                ->maxLength(120),
            Forms\Components\TagsInput::make('classes')
                ->label('Licence categories / vehicle classes')
                ->placeholder('e.g. Light vehicle, Motorcycle')
                ->visible(fn (Get $get): bool => $get('type') === 'licence'),
            Forms\Components\DatePicker::make('issued_on')->label('Issue date')->native(false)->displayFormat('d M Y'),
            Forms\Components\DatePicker::make('expires_on')->label('Expiry date')->native(false)->displayFormat('d M Y')
                ->afterOrEqual('issued_on'),
            $withFile ? Forms\Components\FileUpload::make('path')
                ->label('Copy (photo or PDF)')
                ->disk('private')
                ->directory(fn (RelationManager $livewire): string => 'drivers/'.$livewire->getOwnerRecord()->getKey())
                ->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(10240)
                ->storeFileNamesIn('original_name')
                ->columnSpanFull() : null,
            Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (DriverDocument $record): string => $record->typeLabel())
            ->modifyQueryUsing(fn (Builder $query) => $query->with('uploader'))
            ->defaultSort('type')
            ->columns([
                Tables\Columns\TextColumn::make('type')->label('Document')
                    ->state(fn (DriverDocument $record): string => $record->typeLabel())
                    ->weight('semibold')
                    ->description(fn (DriverDocument $record): ?string => trim(($record->maskedNumber() ?? '').($record->issued_by ? ' · '.$record->issued_by : '').($record->classes ? ' · '.implode(', ', $record->classes) : ''), ' ·') ?: null),
                Tables\Columns\TextColumn::make('expires_on')->label('Expiry')
                    ->state(fn (DriverDocument $record): string => $record->expiryLabel())
                    ->badge()
                    ->color(fn (DriverDocument $record): string => $record->replaced_at ? 'gray' : $record->expiryColor())
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('expires_on', $direction)),
                Tables\Columns\TextColumn::make('replaced_at')->label('Status')
                    ->state(fn (DriverDocument $record): string => $record->replaced_at ? 'Replaced '.$record->replaced_at->format('d M Y') : 'Current')
                    ->color(fn (DriverDocument $record): string => $record->replaced_at ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('created_at')->label('Added')
                    ->since()
                    ->description(fn (DriverDocument $record): ?string => $record->uploader?->name)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('current')
                    ->label('Show')
                    ->placeholder('All copies')
                    ->trueLabel('Current only')
                    ->falseLabel('Replaced only')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('replaced_at'),
                        false: fn (Builder $q) => $q->whereNotNull('replaced_at'),
                        blank: fn (Builder $q) => $q,
                    )
                    ->default(true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add document')
                    ->modalHeading('Add a document')
                    ->form(self::fields())
                    ->mutateFormDataUsing(fn (array $data): array => $data + ['uploaded_by' => auth()->id()])
                    ->authorize(fn (): bool => Access::allows('manage-drivers')),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->iconButton()->icon('heroicon-o-eye')->tooltip('Open file')
                    ->visible(fn (DriverDocument $record): bool => filled($record->path))
                    ->url(fn (DriverDocument $record): ?string => $record->viewUrl(), shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading('Update details')
                    ->form(self::fields(false))
                    ->visible(fn (DriverDocument $record): bool => ! $record->replaced_at)
                    ->authorize(fn (): bool => Access::allows('manage-drivers')),
                Tables\Actions\Action::make('replace')
                    ->iconButton()->icon('heroicon-o-arrow-path')->tooltip('Replace with a new copy')
                    ->visible(fn (DriverDocument $record): bool => ! $record->replaced_at && Access::allows('manage-drivers'))
                    ->modalHeading(fn (DriverDocument $record): string => 'New copy · '.$record->typeLabel())
                    ->modalDescription('The current copy is kept in the history as "replaced".')
                    ->fillForm(fn (DriverDocument $record): array => ['type' => $record->type, 'label' => $record->label, 'issued_by' => $record->issued_by, 'classes' => $record->classes])
                    ->form(fn (DriverDocument $record): array => self::fields(true, $record->type))
                    ->action(function (DriverDocument $record, array $data): void {
                        $copy = $this->getOwnerRecord()->driverDocuments()->create($data + ['type' => $record->type, 'uploaded_by' => auth()->id()]);
                        if ($record->type === 'other') {
                            $record->update(['replaced_at' => now()]); // "other" papers are not replaced automatically
                        }
                        \Filament\Notifications\Notification::make()->title($copy->typeLabel().' replaced')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Add the passport, ID, licence and any permits with their expiry dates.');
    }
}
