<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailTemplateResource\Pages;
use App\Models\EmailTemplate;
use App\Support\Access;
use App\Support\Mail\Templates;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** Settings › Email templates (4 Nov, 14.3): English and Arabic, with placeholders and a preview. */
class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Email templates';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'email-templates';

    public static function canViewAny(): bool
    {
        return Access::allows('manage-email');
    }

    public static function canCreate(): bool
    {
        return Access::allows('manage-email');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::allows('manage-email');
    }

    public static function canDelete(Model $record): bool
    {
        return Access::allows('manage-email');
    }

    public static function form(Form $form): Form
    {
        $preview = function (Get $get, string $lang): HtmlString {
            $t = new EmailTemplate(['subject_en' => (string) $get('subject_en'), 'body_en' => (string) $get('body_en'), 'subject_ar' => $get('subject_ar'), 'body_ar' => $get('body_ar')]);
            $sample = \App\Models\Booking::query()->latest('id')->first() ?? \App\Models\Customer::query()->latest('id')->first();
            $r = Templates::render($t, $sample, $lang);

            return new HtmlString('<div style="font-size:.9rem;border:1px solid rgba(127,127,127,.3);border-radius:10px;padding:12px'.($lang === 'ar' ? ';direction:rtl;text-align:right' : '').'"><b>'.e($r['subject']).'</b><div style="white-space:pre-line;margin-top:6px">'.e($r['body']).'</div></div>'
                .'<div style="font-size:.75rem;opacity:.65;margin-top:4px">'.($sample ? 'Filled in with '.e($sample instanceof \App\Models\Booking ? $sample->reference : $sample->name).'.' : 'No booking yet to fill in the example.').'</div>');
        };

        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\Select::make('audience')->options(EmailTemplate::AUDIENCES)->required()->native(false)->default('customer'),
            Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->inline(false),
            Forms\Components\Placeholder::make('help')->hiddenLabel()->columnSpanFull()
                ->content('You can use: '.implode(' ', Templates::PLACEHOLDERS)),
            Forms\Components\Tabs::make('lang')->columnSpanFull()->tabs([
                Forms\Components\Tabs\Tab::make('English')->schema([
                    Forms\Components\TextInput::make('subject_en')->label('Subject')->required()->maxLength(190)->live(onBlur: true),
                    Forms\Components\Textarea::make('body_en')->label('Text')->required()->rows(8)->live(onBlur: true),
                    Forms\Components\Placeholder::make('preview_en')->label('Preview')->content(fn (Get $get) => $preview($get, 'en')),
                ]),
                Forms\Components\Tabs\Tab::make('Arabic')->schema([
                    Forms\Components\TextInput::make('subject_ar')->label('Subject')->maxLength(190)->extraInputAttributes(['dir' => 'rtl'])->live(onBlur: true),
                    Forms\Components\Textarea::make('body_ar')->label('Text')->rows(8)->extraInputAttributes(['dir' => 'rtl'])->live(onBlur: true)
                        ->helperText('Optional. Used for customers whose language is Arabic.'),
                    Forms\Components\Placeholder::make('preview_ar')->label('Preview')->content(fn (Get $get) => filled($get('body_ar')) ? $preview($get, 'ar') : new HtmlString('<span style="opacity:.6">No Arabic text: the English one is used.</span>')),
                ]),
            ]),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('bold')->description(fn (EmailTemplate $t) => $t->subject_en)->searchable(),
                Tables\Columns\TextColumn::make('audience')->badge()->formatStateUsing(fn ($state) => EmailTemplate::AUDIENCES[$state] ?? $state),
                Tables\Columns\IconColumn::make('body_ar')->label('Arabic')->boolean()->state(fn (EmailTemplate $t) => filled($t->body_ar)),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageEmailTemplates::route('/')];
    }
}
