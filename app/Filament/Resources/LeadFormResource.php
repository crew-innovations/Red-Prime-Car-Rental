<?php

namespace App\Filament\Resources;

use App\Enums\ServiceType;
use App\Filament\Resources\LeadFormResource\Pages;
use App\Models\LeadForm;
use App\Support\Access;
use App\Support\Leads\Assignment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Sales › Shared forms (brief 28 Oct-2, Phase 16): make a form, share its link, answers arrive as leads. */
class LeadFormResource extends Resource
{
    protected static ?string $model = LeadForm::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Shared forms';

    protected static ?string $modelLabel = 'shared form';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-leads');
    }

    public static function canCreate(): bool
    {
        return Access::allows('assign-leads');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::allows('assign-leads');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // switch it off instead: leads keep pointing to it
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')->label('Title shown on the form')->required()->maxLength(120),
            Forms\Components\Select::make('service_type')->label('Service')->options(ServiceType::class)->helperText('The leads get this service.'),
            Forms\Components\Textarea::make('intro')->label('Text above the form')->rows(2)->maxLength(1000)->columnSpanFull(),
            Forms\Components\Placeholder::make('fixed')->hiddenLabel()->columnSpanFull()
                ->content('Name and mobile are always asked (required), email is optional. Add any other questions below.'),
            Forms\Components\Repeater::make('fields')->label('Questions')->columnSpanFull()->defaultItems(0)->reorderable()->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->addActionLabel('Add a question')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('label')->label('Question')->required()->maxLength(150)->columnSpan(2),
                    Forms\Components\Select::make('type')->options(LeadForm::TYPES)->default('text')->required()->live(),
                    Forms\Components\TagsInput::make('options')->label('Choices')->columnSpan(2)
                        ->visible(fn (Forms\Get $get): bool => $get('type') === 'select')->required(fn (Forms\Get $get): bool => $get('type') === 'select'),
                    Forms\Components\Toggle::make('required')->inline(false),
                    Forms\Components\Hidden::make('key'),
                ]),
            Forms\Components\Textarea::make('thank_you')->label('Message after sending')->rows(2)->maxLength(1000)->placeholder('We received your details. Our team will contact you shortly.')->columnSpanFull(),
            Forms\Components\Select::make('assign_to')->label('Give the leads to')->placeholder('Use the assignment rules')
                ->options(fn () => Assignment::eligible()->orderBy('name')->pluck('name', 'id')->all()),
            Forms\Components\Toggle::make('is_active')->label('Open')->default(true)->inline(false),
            Forms\Components\DateTimePicker::make('expires_at')->label('Closes on')->seconds(false)->helperText('Empty = no end date.'),
            Forms\Components\TextInput::make('max_submissions')->label('Close after this many answers')->numeric()->minValue(1)->helperText('Empty = no limit.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight(FontWeight::SemiBold)->searchable()
                    ->description(fn (LeadForm $r): ?string => $r->service_type?->getLabel()),
                Tables\Columns\TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (LeadForm $r): string => $r->closedReason() ? rtrim(str_replace('This form ', '', $r->closedReason()), '.') : 'open')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('is ', '', $state)))
                    ->color(fn (string $state): string => $state === 'open' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('submissions')->label('Answers')
                    ->formatStateUsing(fn ($state, LeadForm $r): string => $state.($r->max_submissions ? ' / '.$r->max_submissions : '')),
                Tables\Columns\TextColumn::make('expires_at')->label('Closes')->dateTime('d M Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('owner.name')->label('Leads go to')->placeholder('Assignment rules'),
            ])
            ->actions([
                Tables\Actions\Action::make('link')->label('Link')->icon('heroicon-o-link')->color('gray')
                    ->modalHeading(fn (LeadForm $r): string => $r->name)->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->form(fn (LeadForm $r): array => [
                        Forms\Components\TextInput::make('url')->label('Share this link')->default($r->url())->readOnly()->extraInputAttributes(['onclick' => 'this.select()'])
                            ->helperText('Add ?utm_source=…&utm_campaign=… to track where it was shared.'),
                    ]),
                Tables\Actions\EditAction::make()->modalWidth('4xl'),
                Tables\Actions\Action::make('newLink')->label('New link')->icon('heroicon-o-arrow-path')->color('gray')
                    ->authorize('assign-leads')
                    ->requiresConfirmation()->modalDescription('The old link stops working at once. Use this if the link was shared somewhere it should not be.')
                    ->action(function (LeadForm $r) {
                        $r->forceFill(['token' => Str::random(40)])->save();
                        Notification::make()->title('New link made')->body($r->url())->success()->send();
                    }),
            ])
            ->emptyStateHeading('No shared forms yet')
            ->emptyStateDescription('Make a form, share its link (WhatsApp, Instagram bio, a QR code at the office), and every answer arrives in Leads.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLeadForms::route('/')];
    }
}
