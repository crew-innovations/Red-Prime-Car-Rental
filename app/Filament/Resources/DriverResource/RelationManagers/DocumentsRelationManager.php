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
        // 20 Nov: documents waiting for approval count too.
        $n = $ownerRecord->driverDocuments()->current()->where(fn ($q) => $q->where('status', 'pending')
            ->orWhere(fn ($q2) => $q2->whereNotNull('expires_on')->where('expires_on', '<=', today()->addDays(DriverDocument::warnDays()))))->count();

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
            // 20 Nov (item 8): the required documents and where each stands, above the list.
            ->description(fn (): ?\Illuminate\Support\HtmlString => self::checklistHtml($this->getOwnerRecord()))
            ->recordTitle(fn (DriverDocument $record): string => $record->typeLabel())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['uploader', 'reviewer']))
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
                Tables\Columns\TextColumn::make('status')->label('Check')
                    ->badge()
                    ->state(fn (DriverDocument $record): string => $record->statusLabel())
                    ->color(fn (DriverDocument $record): string => $record->statusColor())
                    ->description(fn (DriverDocument $record): ?string => $record->status === 'rejected' ? $record->review_note
                        : ($record->reviewed_at ? 'by '.($record->reviewer?->name ?? '—').' · '.$record->reviewed_at->format('d M') : null)),
                Tables\Columns\TextColumn::make('replaced_at')->label('Copy')
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
                Tables\Filters\SelectFilter::make('status')->label('Check')->options(DriverDocument::STATUSES),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add document')
                    ->modalHeading('Add a document')
                    ->form(self::fields())
                    // The office's own upload is approved straight away.
                    ->mutateFormDataUsing(fn (array $data): array => $data + ['uploaded_by' => auth()->id(), 'status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()])
                    ->authorize(fn (): bool => Access::allows('manage-drivers')),
            ])
            ->actions([
                // 20 Nov (item 8): approve / reject what the driver uploaded.
                Tables\Actions\Action::make('approve')
                    ->label('Approve')->icon('heroicon-o-check')->color('success')->button()->size('sm')
                    ->visible(fn (DriverDocument $record): bool => $record->status !== 'approved' && ! $record->replaced_at && Access::allows('manage-drivers'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (DriverDocument $record): string => 'Approve '.$record->typeLabel().'?')
                    ->modalDescription('Check the copy, the number and the expiry date first. An older approved copy is kept as history.')
                    ->action(function (DriverDocument $record): void {
                        $record->update(['status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => null]);
                        \App\Support\Drivers\Notify::driver($record->user, __('driver.doc_approved', ['doc' => $record->typeLabel()]), null, \App\Filament\Pages\MyProfile::getUrl(), 'success', 'other');
                        \Filament\Notifications\Notification::make()->title($record->typeLabel().' approved')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')->icon('heroicon-o-x-mark')->color('danger')->button()->size('sm')
                    ->visible(fn (DriverDocument $record): bool => $record->status === 'pending' && Access::allows('manage-drivers'))
                    ->form([Forms\Components\Textarea::make('review_note')->label('Why (the driver sees this)')->required()->rows(2)->maxLength(500)])
                    ->action(function (DriverDocument $record, array $data): void {
                        $record->update(['status' => 'rejected', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $data['review_note']]);
                        \App\Support\Drivers\Notify::driver($record->user, __('driver.doc_rejected', ['doc' => $record->typeLabel()]), $data['review_note'], \App\Filament\Pages\MyProfile::getUrl(), 'danger', 'other');
                        \Filament\Notifications\Notification::make()->title($record->typeLabel().' rejected')->body('The driver has been told and can send it again.')->send();
                    }),
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
                        $copy = $this->getOwnerRecord()->driverDocuments()->create($data + ['type' => $record->type, 'uploaded_by' => auth()->id(),
                            'status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                        if ($record->type === 'other') {
                            $record->update(['replaced_at' => now()]); // "other" papers are not replaced automatically
                        }
                        \Filament\Notifications\Notification::make()->title($copy->typeLabel().' replaced')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Add the passport, ID, licence and any permits with their expiry dates.');
    }

    /** Required documents with their state (missing, waiting, approved, rejected, expired). */
    public static function checklistHtml(\App\Models\User $driver): ?\Illuminate\Support\HtmlString
    {
        $rows = \App\Support\Drivers\DriverPapers::checklist($driver);
        if ($rows === []) {
            return new \Illuminate\Support\HtmlString('<span style="opacity:.7">No documents are required in Business settings › Drivers.</span>');
        }
        $colors = ['approved' => '#16a34a', 'pending' => '#d97706', 'rejected' => '#dc2626', 'expired' => '#dc2626', 'missing' => '#dc2626'];
        $labels = ['approved' => 'Approved', 'pending' => 'Waiting for approval', 'rejected' => 'Rejected', 'expired' => 'Expired', 'missing' => 'Missing'];
        $out = collect($rows)->map(fn ($r) => '<span style="display:inline-block;margin:.15rem .6rem .15rem 0"><b>'.e($r['label']).'</b>: <span style="color:'.$colors[$r['state']].'">'.$labels[$r['state']]
            .($r['state'] === 'approved' && $r['waiting'] ? ' (new copy waiting)' : '').'</span></span>')->implode('');

        return new \Illuminate\Support\HtmlString('Required: '.$out.'<br><span style="opacity:.7">Missing documents never stop a job being given; staff see a warning.</span>');
    }
}
