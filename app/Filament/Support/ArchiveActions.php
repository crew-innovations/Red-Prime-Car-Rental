<?php

namespace App\Filament\Support;

use App\Models\AuditLog;
use Filament\Actions;
use Filament\Forms;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 5 Nov ("Make it simple", B.5): nothing is hard-deleted from the screens.
 *  - Only Admin (delete-records) ever sees these buttons; agents and Accounts never do.
 *  - Business records (leads, customers, quotations, bookings, money, documents…) can come back:
 *    "Delete" becomes "Archive", with a reason that goes to the audit log.
 *  - Set-up lists without archive (trip dates, maintenance lines, lead sources…) keep "Delete"
 *    for Admin, with a reason; history (lead activity notes) can't be deleted at all.
 *  - "Force delete" (gone for good) is never shown.
 */
class ArchiveActions
{
    /** History that is never deleted from the screens, even by Admin. */
    public const PROTECTED = [\App\Models\LeadActivity::class, \App\Models\ChatMessage::class, \App\Models\AuditLog::class];

    public static function softDeletes(Model|string|null $model): bool
    {
        return $model !== null && in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    public static function hide(Model|string|null $model): bool
    {
        if (! \App\Support\Access::allows('delete-records')) {
            return true;
        }
        $class = is_object($model) ? get_class($model) : $model;

        return $class !== null && in_array($class, self::PROTECTED, true);
    }

    public static function configure(): void
    {
        $reason = fn () => [Forms\Components\TextInput::make('archive_reason')->label('Why?')->required()->maxLength(255)];
        $log = function (?Model $record, array $data): void {
            if ($record) {
                rescue(fn () => AuditLog::note($record, 'archive', (self::softDeletes($record) ? 'Archived: ' : 'Deleted: ').($data['archive_reason'] ?? '')), report: false);
            }
        };

        foreach ([Actions\DeleteAction::class, Tables\Actions\DeleteAction::class] as $class) {
            $class::configureUsing(fn ($action) => $action
                ->label(fn (?Model $record): string => $record && ! self::softDeletes($record) ? 'Delete' : 'Archive')
                ->icon(fn (?Model $record): string => $record && ! self::softDeletes($record) ? 'heroicon-o-trash' : 'heroicon-o-archive-box')
                ->modalHeading(fn (?Model $record): string => $record && ! self::softDeletes($record) ? 'Delete' : 'Archive')
                ->modalDescription(fn (?Model $record): string => $record && ! self::softDeletes($record)
                    ? 'This line is removed for good.'
                    : 'It disappears from the lists but is kept, and an Admin can bring it back.')
                ->modalSubmitActionLabel(fn (?Model $record): string => $record && ! self::softDeletes($record) ? 'Delete' : 'Archive')
                ->successNotificationTitle(fn (?Model $record): string => $record && ! self::softDeletes($record) ? 'Deleted' : 'Archived')
                ->form($reason())
                ->before(fn (?Model $record, array $data) => $log($record, $data))
                ->hidden(fn (?Model $record): bool => self::hide($record)));
        }

        Tables\Actions\DeleteBulkAction::configureUsing(fn ($action) => $action
            ->label('Archive selected')
            ->icon('heroicon-o-archive-box')
            ->modalHeading('Archive the selected records')
            ->modalSubmitActionLabel('Archive')
            ->successNotificationTitle('Archived')
            ->form($reason())
            ->before(function ($records, array $data) use ($log) {
                foreach ($records as $r) {
                    $log($r, $data);
                }
            })
            ->hidden(fn ($livewire): bool => self::hide(rescue(fn () => $livewire->getTable()->getModel(), null, false))));

        foreach ([Actions\ForceDeleteAction::class, Tables\Actions\ForceDeleteAction::class, Tables\Actions\ForceDeleteBulkAction::class] as $class) {
            $class::configureUsing(fn ($action) => $action->hidden());
        }

        foreach ([Actions\RestoreAction::class, Tables\Actions\RestoreAction::class] as $class) {
            $class::configureUsing(fn ($action) => $action->label('Bring back'));
        }
    }
}
