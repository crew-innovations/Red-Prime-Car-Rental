<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CustomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Set or add the extra status on a lead, booking or quotation. */
class CustomStatuses
{
    public static function apply(Model $record, string $type, array $data): ?CustomStatus
    {
        if (! in_array($type, CustomStatus::TYPES, true)) {
            throw ValidationException::withMessages(['custom_status_id' => 'This record cannot have a custom status.']);
        }

        $name = trim((string) ($data['new_name'] ?? ''));
        if ($name !== '') {
            abort_unless(Access::isAdmin(), 403);
            if (mb_strlen($name) > 80) {
                throw ValidationException::withMessages(['new_name' => 'Keep the status to 80 characters.']);
            }
            $color = array_key_exists($data['color'] ?? '', CustomStatus::COLORS) ? $data['color'] : 'gray';
            $status = CustomStatus::query()->firstOrCreate(
                ['record_type' => $type, 'name' => $name],
                ['color' => $color, 'is_active' => true, 'created_by' => auth()->id()],
            );
            if (! $status->is_active) {
                $status->forceFill(['is_active' => true])->save();
            }
        } else {
            $id = $data['custom_status_id'] ?? null;
            $status = filled($id)
                ? CustomStatus::query()->where('record_type', $type)->where('is_active', true)->find($id)
                : null;
            if (filled($id) && ! $status) {
                throw ValidationException::withMessages(['custom_status_id' => 'Choose a status for this record.']);
            }
        }

        $record->forceFill(['custom_status_id' => $status?->id])->save();
        AuditLog::note($record, 'custom_status', $status ? 'Custom status set to '.$status->name : 'Custom status cleared', [
            'custom_status_id' => $status?->id,
        ]);

        return $status;
    }
}
