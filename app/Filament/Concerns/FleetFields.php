<?php

namespace App\Filament\Concerns;

use Filament\Forms;
use Filament\Forms\Get;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/** Form and table pieces shared by Cars and Yachts (and Trips where relevant). */
class FleetFields
{
    public const WARNING_DAYS = 30;

    /** Date picker that warns when the date is close or passed. */
    public static function expiryDate(string $name, string $label): Forms\Components\DatePicker
    {
        $isDue = fn (?string $state): bool => $state && Carbon::parse($state)->lte(today()->addDays(self::WARNING_DAYS));

        return Forms\Components\DatePicker::make($name)
            ->label($label)
            ->native(false)
            ->displayFormat('d M Y')
            ->live()
            ->hint(function (?string $state) use ($isDue): ?string {
                if (! $isDue($state)) {
                    return null;
                }
                $days = (int) today()->diffInDays(Carbon::parse($state), false);

                return $days < 0 ? 'Overdue' : "Due in {$days} days";
            })
            ->hintColor('danger')
            ->hintIcon(fn (?string $state): ?string => $isDue($state) ? 'heroicon-o-exclamation-triangle' : null);
    }

    public static function ownershipFieldset(string $ownLabel, string $partnerLabel): Forms\Components\Fieldset
    {
        $isPartner = fn (Get $get): bool => $get('ownership') === 'partner';

        return Forms\Components\Fieldset::make('Ownership')
            ->columns(3)
            ->schema([
                Forms\Components\ToggleButtons::make('ownership')
                    ->options(['own' => $ownLabel, 'partner' => $partnerLabel])
                    ->icons(['own' => 'heroicon-o-building-office', 'partner' => 'heroicon-o-users'])
                    ->default('own')
                    ->inline()
                    ->live(),
                Forms\Components\TextInput::make('partner_name')
                    ->required($isPartner)
                    ->visible($isPartner)
                    ->maxLength(100),
                Forms\Components\TextInput::make('partner_commission')
                    ->label('Partner share')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->visible($isPartner),
            ]);
    }

    /** @param array<string, string> $units */
    public static function addonsRepeater(array $suggestions, array $units): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('addons')
            ->label('Add-ons')
            ->helperText('Optional extras the customer can add to a booking.')
            ->columns(3)
            ->defaultItems(0)
            ->addActionLabel('Add an add-on')
            ->reorderable()
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->datalist($suggestions),
                Forms\Components\TextInput::make('price')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('AED')
                    ->required(),
                Forms\Components\Select::make('unit')
                    ->options($units)
                    ->default(array_key_first($units))
                    ->required()
                    ->native(false),
            ]);
    }

    /** Red sidebar box listing expired / expiring documents. */
    public static function alertsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Needs attention')
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('danger')
            ->visible(fn (?Model $record): bool => filled($record?->documentAlerts()))
            ->schema([
                Forms\Components\Placeholder::make('alerts')
                    ->hiddenLabel()
                    ->content(fn (?Model $record): HtmlString => new HtmlString(
                        collect($record?->documentAlerts() ?? [])
                            ->map(fn (string $msg) => '<div style="color: rgb(var(--danger-500)); font-size: .875rem">• '.e($msg).'</div>')
                            ->implode('')
                    )),
            ]);
    }

    public static function metaSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make()
            ->hiddenOn('create')
            ->schema([
                Forms\Components\Placeholder::make('created_at')
                    ->label('Added')
                    ->inlineLabel()
                    ->content(fn (?Model $record): string => $record?->created_at?->format('d M Y, H:i') ?? '-'),
                Forms\Components\Placeholder::make('updated_at')
                    ->label('Last updated')
                    ->inlineLabel()
                    ->content(fn (?Model $record): string => $record?->updated_at?->diffForHumans() ?? '-'),
            ]);
    }

    public static function notesSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Internal notes')
            ->icon('heroicon-o-lock-closed')
            ->collapsible()
            ->schema([
                Forms\Components\Textarea::make('notes')
                    ->hiddenLabel()
                    ->placeholder('Only visible to your team.')
                    ->rows(4),
            ]);
    }

    public static function documentsColumn(): Tables\Columns\IconColumn
    {
        return Tables\Columns\IconColumn::make('document_alerts')
            ->label('Docs')
            ->state(fn (Model $record): bool => filled($record->documentAlerts()))
            ->boolean()
            ->trueIcon('heroicon-o-exclamation-triangle')
            ->trueColor('danger')
            ->falseIcon('heroicon-o-check-circle')
            ->falseColor('success')
            ->tooltip(fn (Model $record): string => implode(' · ', $record->documentAlerts()) ?: 'All documents up to date');
    }

    public static function ownershipColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('ownership')
            ->badge()
            ->formatStateUsing(fn (string $state): string => $state === 'partner' ? 'Partner' : 'Own')
            ->color(fn (string $state): string => $state === 'partner' ? 'info' : 'gray')
            ->description(fn (Model $record): ?string => $record->partner_name)
            ->toggleable(isToggledHiddenByDefault: true);
    }

    public static function documentsDueFilter(): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make('documents_due')
            ->label('Documents or service due in 30 days')
            ->toggle()
            ->query(fn ($query) => $query->documentsDueSoon());
    }

    public static function badgeSelect(array $badges): Forms\Components\Select
    {
        return Forms\Components\Select::make('website_badge')
            ->label('Badge on the website card')
            ->options(array_combine($badges, $badges))
            ->placeholder('No badge')
            ->native(false);
    }
}
