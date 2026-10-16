<?php

namespace App\Filament\Concerns;

/**
 * For edit pages of resources using GuardedResource: people without the manage
 * ability see the record read-only, and the server refuses a save from them.
 */
trait GuardsSave
{
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_unless(static::getResource()::canModify(), 403);

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function getFormActions(): array
    {
        return static::getResource()::canModify() ? parent::getFormActions() : [$this->getCancelFormAction()->label('Back')];
    }
}
