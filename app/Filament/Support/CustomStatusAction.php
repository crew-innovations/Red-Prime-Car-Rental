<?php

namespace App\Filament\Support;

use App\Models\CustomStatus;
use App\Support\Access;
use App\Support\CustomStatuses;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/** The Custom status button on a lead, booking or quotation. */
class CustomStatusAction
{
    public static function make(string $action, string $type): \Filament\Actions\Action
    {
        return $action::make('customStatus')
            ->label('Custom status')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->modalHeading('Custom status')
            ->modalDescription('An extra label for your team. The normal status stays as it is. Only an Admin can add a new one.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (Model $record): array => [
                'custom_status_id' => $record->custom_status_id,
                'color' => 'gray',
            ])
            ->form([
                Forms\Components\Select::make('custom_status_id')
                    ->label('Status')
                    ->options(fn (): array => CustomStatus::options($type))
                    ->placeholder('None')
                    ->native(false)
                    ->searchable()
                    ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($type): void {
                        if (filled($value) && ! CustomStatus::query()->where('record_type', $type)->where('is_active', true)->whereKey($value)->exists()) {
                            $fail('Choose a status for this record.');
                        }
                    }),
                Forms\Components\TextInput::make('new_name')
                    ->label('Add a new status')
                    ->maxLength(80)
                    ->live(onBlur: true)
                    ->visible(fn (): bool => Access::isAdmin())
                    ->helperText('Saved for every '.match ($type) {
                        'lead' => 'lead',
                        'booking' => 'booking',
                        default => 'quotation',
                    }.' after this.'),
                Forms\Components\Select::make('color')
                    ->label('Colour')
                    ->options(CustomStatus::COLORS)
                    ->default('gray')
                    ->native(false)
                    ->visible(fn (Forms\Get $get): bool => Access::isAdmin() && filled($get('new_name'))),
            ])
            ->action(function (Model $record, array $data) use ($type): void {
                $status = CustomStatuses::apply($record, $type, $data);
                Notification::make()
                    ->title($status ? 'Custom status: '.$status->name : 'Custom status cleared')
                    ->success()
                    ->send();
            });
    }

    public static function column(): \Filament\Tables\Columns\TextColumn
    {
        return \Filament\Tables\Columns\TextColumn::make('customStatus.name')
            ->label('Custom status')
            ->badge()
            ->placeholder('—')
            ->color(fn (Model $record): string => $record->customStatus?->color ?: 'gray')
            ->toggleable();
    }
}
