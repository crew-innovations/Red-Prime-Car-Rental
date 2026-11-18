<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\EmailLogResource;
use App\Models\EmailLog;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** "Emails" tab on customer and driver profiles (4 Nov, 14.4): what was sent to them. */
class EmailsRelationManager extends RelationManager
{
    protected static string $relationship = 'emailLogs';

    protected static ?string $title = 'Emails';

    protected static ?string $icon = 'heroicon-o-envelope';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return EmailLogResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(EmailLogResource::columns())
            ->actions([Tables\Actions\ViewAction::make(), EmailLogResource::resendAction()])
            ->emptyStateHeading('No emails sent yet');
    }
}
