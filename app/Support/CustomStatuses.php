<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CustomStatus;
use Filament\Forms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Extra statuses an Admin adds into the same list as the fixed ones.
 * Picking one applies it to the record. The fixed status (pipeline stage,
 * booking status, quotation status) is left as it is.
 */
class CustomStatuses
{
    public static function key(int $id): string
    {
        return 'x-'.$id;
    }

    public static function isExtra(mixed $value): bool
    {
        return is_string($value) && preg_match('/^x-\d+$/', $value) === 1;
    }

    /** The fixed status a form check should use when an extra status is selected. */
    public static function asFixed(mixed $choice, ?string $fallback): ?string
    {
        return self::isExtra($choice) ? $fallback : (filled($choice) ? (string) $choice : $fallback);
    }

    /** @param  array<string, string>  $fixed
     * @return array<string, string>
     */
    public static function withExtras(string $type, array $fixed): array
    {
        foreach (CustomStatus::options($type) as $id => $name) {
            $fixed[self::key((int) $id)] = $name;
        }

        return $fixed;
    }

    /** @return array<string, string> */
    public static function colors(string $type): array
    {
        return CustomStatus::query()
            ->where('record_type', $type)
            ->where('is_active', true)
            ->pluck('color', 'id')
            ->mapWithKeys(fn ($color, $id) => [self::key((int) $id) => $color ?: 'gray'])
            ->all();
    }

    public static function nameField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('new_status')
            ->label('Add a status')
            ->maxLength(80)
            ->visible(fn (): bool => Access::isAdmin())
            ->helperText('Saved into this list. The fixed statuses stay as they are.');
    }

    /**
     * Read the status list and the "Add a status" box.
     * An extra status is stored on the record. A fixed status is written only when $writeFixed is true.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function take(array $data, string $type, string $field, ?string $keepFixed = null, bool $writeFixed = true, ?Model $record = null): array
    {
        if (! in_array($type, CustomStatus::TYPES, true)) {
            throw ValidationException::withMessages([$field => 'This record cannot have a status added.']);
        }

        $new = trim((string) ($data['new_status'] ?? ''));
        unset($data['new_status']);
        $choice = $data[$field] ?? null;

        if ($new !== '') {
            abort_unless(Access::isAdmin(), 403);
            if (mb_strlen($new) > 80) {
                throw ValidationException::withMessages(['new_status' => 'Keep the status to 80 characters.']);
            }
            $status = CustomStatus::query()->firstOrCreate(
                ['record_type' => $type, 'name' => $new],
                ['color' => 'gray', 'is_active' => true, 'created_by' => auth()->id()],
            );
            if (! $status->is_active) {
                $status->forceFill(['is_active' => true])->save();
            }
            $data['custom_status_id'] = $status->id;
            if (! self::isFixedChoice($choice)) {
                $data[$field] = $keepFixed;
            }
            self::note($record, $status);

            return self::finish($data, $field);
        }

        if (self::isExtra($choice)) {
            $status = CustomStatus::query()
                ->where('record_type', $type)
                ->where('is_active', true)
                ->find((int) substr((string) $choice, 2));
            if (! $status) {
                throw ValidationException::withMessages([$field => 'Choose a status from the list.']);
            }
            $data['custom_status_id'] = $status->id;
            $data[$field] = $keepFixed;
            self::note($record, $status);

            return self::finish($data, $field);
        }

        if (filled($choice) && ($writeFixed || ($keepFixed !== null && (string) $choice === $keepFixed))) {
            if ($writeFixed) {
                $data[$field] = $choice;
            }
            $data['custom_status_id'] = null;
            self::note($record, null);
        }
        if (! $writeFixed) {
            unset($data[$field]);
        }

        return self::finish($data, $field);
    }

    protected static function isFixedChoice(mixed $choice): bool
    {
        return filled($choice) && ! self::isExtra($choice);
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function finish(array $data, string $field): array
    {
        if (! array_key_exists($field, $data) || $data[$field] === null || $field === 'status_choice') {
            unset($data[$field]);
        }

        return $data;
    }

    protected static function note(?Model $record, ?CustomStatus $status): void
    {
        if (! $record?->exists || (int) $record->custom_status_id === (int) ($status?->id)) {
            return;
        }
        AuditLog::note($record, 'custom_status', $status ? 'Status set to '.$status->name : 'Extra status cleared', [
            'custom_status_id' => $status?->id,
        ]);
    }
}
