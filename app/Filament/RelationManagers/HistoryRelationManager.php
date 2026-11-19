<?php

namespace App\Filament\RelationManagers;

use App\Models\AuditLog;
use App\Support\Access;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** "History" tab: who changed what and when. Read-only. */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'History';

    protected static ?string $icon = 'heroicon-o-clock';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::allows('view-history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('event')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Who')->placeholder('System'),
                Tables\Columns\TextColumn::make('event')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'override' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('changes')
                    ->label('What changed')
                    ->state(fn (AuditLog $record): HtmlString => new HtmlString(self::describe($record)))
                    ->wrap(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No changes recorded yet');
    }

    public static function describe(AuditLog $log): string
    {
        return \App\Support\Audit\AuditView::summary($log, 12);
    }
}
