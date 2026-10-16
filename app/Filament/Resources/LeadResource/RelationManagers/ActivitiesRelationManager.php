<?php

namespace App\Filament\Resources\LeadResource\RelationManagers;

use App\Enums\ActivityType;
use App\Models\LeadActivity;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Call / WhatsApp / meeting log for a lead. */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activity log';

    protected static ?string $icon = 'heroicon-o-chat-bubble-bottom-center-text';

    public const OUTCOMES = [
        'reached' => 'Reached',
        'no_answer' => 'No answer',
        'interested' => 'Interested',
        'sent_quote' => 'Sent prices / quote',
        'not_interested' => 'Not interested',
    ];

    public function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\ToggleButtons::make('type')
                    ->options(ActivityType::class)
                    ->default(ActivityType::WhatsApp)
                    ->inline()
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('outcome')
                    ->options(self::OUTCOMES)
                    ->native(false),
                Forms\Components\DateTimePicker::make('next_follow_up_at')
                    ->label('Next follow-up')
                    ->helperText('Updates the lead.')
                    ->native(false)
                    ->seconds(false)
                    ->minDate(now()->startOfDay())
                    ->hiddenOn('edit'),
                Forms\Components\Textarea::make('body')
                    ->label('What happened?')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip('d M Y, H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('outcome')
                    ->formatStateUsing(fn (?string $state): ?string => self::OUTCOMES[$state] ?? $state)
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('body')
                    ->label('Notes')
                    ->wrap()
                    ->limit(120),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('System'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Log activity')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Log a call, message or meeting')
                    ->mutateFormDataUsing(function (array $data): array {
                        if (! empty($data['next_follow_up_at'])) {
                            $this->getOwnerRecord()->update(['next_follow_up_at' => $data['next_follow_up_at']]);
                        }
                        unset($data['next_follow_up_at']);

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('Log every call, WhatsApp chat and meeting so the whole team can see the history.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
