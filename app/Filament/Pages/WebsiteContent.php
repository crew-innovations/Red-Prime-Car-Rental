<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Car;
use App\Models\Setting;
use App\Support\Access;
use App\Support\Site\Site;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Offers and customer reviews for the website (M3, 30 Oct). Staff enter real offers and real
 * reviews here; the website never makes up a discount or a review.
 */
class WebsiteContent extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.website-content';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Website offers & reviews';

    protected static ?string $title = 'Website offers & reviews';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'website-content';

    public ?array $data = [];

    public const SOURCES = ['Google' => 'Google', 'Instagram' => 'Instagram', 'TikTok' => 'TikTok', 'Facebook' => 'Facebook', 'WhatsApp' => 'WhatsApp', 'Other' => 'Other'];

    public static function canAccess(): bool
    {
        return Access::allows('manage-fleet');
    }

    public function mount(): void
    {
        $this->form->fill([
            'website_offers' => array_values((array) Setting::read('website_offers', [])),
            'website_reviews' => array_values((array) Setting::read('website_reviews', [])),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Offers')
                    ->description('Shown on redprimerentals.com/offers and on the home page. Only active offers that have not expired are shown. Write the discount exactly as you give it.')
                    ->schema([
                        Forms\Components\Repeater::make('website_offers')->hiddenLabel()
                            ->addActionLabel('Add offer')
                            ->collapsible()->cloneable()->reorderable()
                            ->itemLabel(fn (array $state) => $state['title'] ?? 'New offer')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('title')->required()->maxLength(90)->placeholder('e.g. Weekly G63 deal'),
                                Forms\Components\TextInput::make('badge')->label('Badge')->maxLength(24)->placeholder('e.g. 15% off'),
                                Forms\Components\Textarea::make('text')->label('Details')->rows(2)->maxLength(400)->columnSpanFull(),
                                Forms\Components\Select::make('car')->label('Car (optional)')
                                    ->options(fn () => Car::query()->where('is_published', true)->orderBy('name')->pluck('name', 'slug')->all())
                                    ->searchable()->placeholder('No specific car')
                                    ->helperText('The offer links to this car and uses its photo.'),
                                Forms\Components\Select::make('image')->label('Photo')
                                    ->options(Site::OFFER_IMAGES)->placeholder('Car photo / default')
                                    ->helperText('Used when no car is picked, or the car has no photo.'),
                                Forms\Components\TextInput::make('url')->label('Link (optional)')->url()->maxLength(255)
                                    ->helperText('Empty = the car page, or the quote form.'),
                                Forms\Components\DatePicker::make('valid_until')->label('Valid until')->native(false)
                                    ->helperText('Empty = until you switch it off.'),
                                Forms\Components\Toggle::make('is_active')->label('Show on the website')->default(true),
                            ]),
                    ]),
                Forms\Components\Section::make('Customer reviews')
                    ->description('Copy real reviews from Google, Instagram or WhatsApp (with the customer\'s permission). With no review here, the website only shows a button to your Google reviews.')
                    ->schema([
                        Forms\Components\Repeater::make('website_reviews')->hiddenLabel()
                            ->addActionLabel('Add review')
                            ->collapsible()->reorderable()
                            ->itemLabel(fn (array $state) => $state['name'] ?? 'New review')
                            ->columns(3)
                            ->schema([
                                Forms\Components\TextInput::make('name')->label('Customer name')->required()->maxLength(60)
                                    ->helperText('First name and initial is enough, e.g. "Sara A."'),
                                Forms\Components\Select::make('rating')->options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★'])->default(5)->required(),
                                Forms\Components\Select::make('source')->options(self::SOURCES)->default('Google'),
                                Forms\Components\Textarea::make('text')->label('Review')->required()->rows(3)->maxLength(600)->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();

        $out = [
            'website_offers' => collect($data['website_offers'] ?? [])
                ->filter(fn ($o) => filled($o['title'] ?? null))
                ->map(fn ($o) => [
                    'title' => trim($o['title']), 'badge' => trim((string) ($o['badge'] ?? '')) ?: null,
                    'text' => trim((string) ($o['text'] ?? '')) ?: null, 'car' => $o['car'] ?? null,
                    'image' => array_key_exists((string) ($o['image'] ?? ''), Site::OFFER_IMAGES) ? $o['image'] : null,
                    'url' => $o['url'] ?? null, 'valid_until' => $o['valid_until'] ?? null, 'is_active' => (bool) ($o['is_active'] ?? true),
                ])->values()->all(),
            'website_reviews' => collect($data['website_reviews'] ?? [])
                ->filter(fn ($r) => filled($r['name'] ?? null) && filled($r['text'] ?? null))
                ->map(fn ($r) => [
                    'name' => trim($r['name']), 'text' => trim($r['text']),
                    'rating' => max(1, min(5, (int) ($r['rating'] ?? 5))), 'source' => $r['source'] ?? null,
                ])->values()->all(),
        ];

        $before = Setting::values();
        Setting::write($out);
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'settings',
            'old_values' => collect($before)->only(array_keys($out))->all(),
            'new_values' => $out,
            'note' => 'Website offers & reviews saved',
        ]);

        Notification::make()->success()->title('Saved. The website shows the changes now.')->send();
    }
}
