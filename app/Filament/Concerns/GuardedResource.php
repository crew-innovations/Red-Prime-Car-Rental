<?php

namespace App\Filament\Concerns;

use App\Support\Access;
use Illuminate\Database\Eloquent\Model;

/**
 * Role-based access for a resource. The resource defines:
 *   protected static function manageAbility(): string   // create / edit
 *   protected static function deleteAbility(): string   // delete / restore
 * Staff without the manage ability can still open records read-only.
 */
trait GuardedResource
{
    public static function canModify(): bool
    {
        return Access::allows(static::manageAbility());
    }

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly();
    }

    public static function canCreate(): bool
    {
        return static::canModify();
    }

    public static function canDelete(Model $record): bool
    {
        return Access::allows(static::deleteAbility());
    }

    public static function canDeleteAny(): bool
    {
        return Access::allows(static::deleteAbility());
    }

    public static function canForceDelete(Model $record): bool
    {
        return Access::allows(static::deleteAbility());
    }

    public static function canForceDeleteAny(): bool
    {
        return Access::allows(static::deleteAbility());
    }

    public static function canRestore(Model $record): bool
    {
        return Access::allows(static::deleteAbility());
    }

    public static function canRestoreAny(): bool
    {
        return Access::allows(static::deleteAbility());
    }

    public static function canReplicate(Model $record): bool
    {
        return static::canModify();
    }

    public static function canReorder(): bool
    {
        return static::canModify();
    }

    protected static function deleteAbility(): string
    {
        return 'delete-records';
    }
}
