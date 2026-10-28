<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> */
    protected array $rolesBefore = [];

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->record->getRoleNames()->sort()->values()->all();
    }

    /** Role changes are not plain columns, so they are logged here (brief 28 Oct-2, Phase 8). */
    protected function afterSave(): void
    {
        $after = $this->record->fresh()->getRoleNames()->sort()->values()->all();
        if ($after !== $this->rolesBefore) {
            \App\Models\AuditLog::create([
                'event' => 'permissions',
                'auditable_type' => $this->record->getMorphClass(),
                'auditable_id' => $this->record->getKey(),
                'old_values' => ['roles' => $this->rolesBefore],
                'new_values' => ['roles' => $after],
                'note' => 'Roles of '.$this->record->name.': '.(implode(', ', $this->rolesBefore) ?: 'none').' → '.(implode(', ', $after) ?: 'none'),
            ]);
        }
    }
}
