<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\BlogPostResource\Pages;
use App\Models\BlogPost;
use App\Support\Site\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Website blog posts (M3, 30 Oct). Shown at redprimerentals.com/blog when published. */
class BlogPostResource extends Resource
{
    use GuardedResource;

    protected static function manageAbility(): string
    {
        return 'manage-fleet';
    }

    protected static function deleteAbility(): string
    {
        return 'manage-fleet';
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::canModify();
    }

    protected static ?string $model = BlogPost::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Blog posts';

    protected static ?string $modelLabel = 'blog post';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Post')->columnSpanFull()->tabs([
                Forms\Components\Tabs\Tab::make('English')->schema([
                    Forms\Components\TextInput::make('title')->required()->maxLength(180)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Set $set, ?BlogPost $record) => $record ? null : $set('slug', Str::slug((string) $state))),
                    Forms\Components\TextInput::make('slug')->label('Web address')->prefix('/blog/')->maxLength(180)
                        ->unique(ignoreRecord: true)->helperText('Filled from the title. Changing it later breaks links people already shared.'),
                    Forms\Components\Textarea::make('excerpt')->label('Short summary')->rows(2)->maxLength(300)
                        ->helperText('Shown on the blog list and in Google results.'),
                    Forms\Components\RichEditor::make('body')->label('Article')
                        ->toolbarButtons(['h2', 'h3', 'bold', 'italic', 'link', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo']),
                ]),
                Forms\Components\Tabs\Tab::make('Arabic')->schema([
                    Forms\Components\Placeholder::make('ar_help')->hiddenLabel()
                        ->content('Optional. Empty fields show the English text on the Arabic website.'),
                    Forms\Components\TextInput::make('title_ar')->label('Title (Arabic)')->maxLength(180)->extraInputAttributes(['dir' => 'rtl']),
                    Forms\Components\Textarea::make('excerpt_ar')->label('Short summary (Arabic)')->rows(2)->maxLength(300)->extraInputAttributes(['dir' => 'rtl']),
                    Forms\Components\RichEditor::make('body_ar')->label('Article (Arabic)')->extraInputAttributes(['dir' => 'rtl'])
                        ->toolbarButtons(['h2', 'h3', 'bold', 'italic', 'link', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo']),
                ]),
                Forms\Components\Tabs\Tab::make('Photo & publishing')->schema([
                    Forms\Components\SpatieMediaLibraryFileUpload::make('cover')
                        ->label('Cover photo')
                        ->collection('cover')
                        ->disk('uploads')
                        ->image()
                        ->imageEditor()
                        ->maxSize(5120),
                    Forms\Components\Select::make('cover_image')->label('Or use a website photo')
                        ->options(Site::OFFER_IMAGES)->placeholder('None')
                        ->helperText('Used only while no cover photo is uploaded.'),
                    Forms\Components\Toggle::make('is_published')->label('Published on the website'),
                    Forms\Components\DateTimePicker::make('published_at')->label('Publish date')->seconds(false)
                        ->helperText('Empty = now. A future date keeps the post hidden until then.'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->wrap()
                    ->description(fn (BlogPost $record) => '/blog/'.$record->slug),
                Tables\Columns\IconColumn::make('title_ar')->label('Arabic')->boolean()
                    ->state(fn (BlogPost $record) => filled($record->title_ar) && filled($record->body_ar)),
                Tables\Columns\IconColumn::make('is_published')->label('Published')->boolean(),
                Tables\Columns\TextColumn::make('published_at')->label('Date')->date('j M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')->label('Published'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')->label('Open on website')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (BlogPost $record) => Site::absolute(Site::route('post', ['slug' => $record->slug], 'en')), true)
                    ->visible(fn (BlogPost $record) => $record->is_published),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogPosts::route('/'),
            'create' => Pages\CreateBlogPost::route('/create'),
            'edit' => Pages\EditBlogPost::route('/{record}/edit'),
        ];
    }
}
