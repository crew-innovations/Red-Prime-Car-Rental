<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/** Settings › Bank, cash and marketplaces (19 Nov, suggestions 2, 4 and 8). */
class MoneySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $title = 'Bank, cash and marketplaces';

    protected static ?string $slug = 'settings/money';

    protected static string $view = 'filament.pages.basic-settings';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public const EMIRATES = ['dubai' => 'Dubai', 'abu_dhabi' => 'Abu Dhabi', 'sharjah' => 'Sharjah', 'ajman' => 'Ajman', 'umm_al_quwain' => 'Umm Al Quwain', 'ras_al_khaimah' => 'Ras Al Khaimah', 'fujairah' => 'Fujairah'];

    public static function canAccess(): bool
    {
        return Access::allows('manage-team') || Access::allows('manage-finance');
    }

    public function mount(): void
    {
        $this->form->fill([
            'bank_accounts' => array_values((array) Setting::read('bank_accounts', [])),
            'tax_emirate_default' => Setting::read('tax_emirate_default', 'dubai'),
            'marketplaces' => (array) Setting::read('marketplaces', []),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Bank and cash accounts')
                ->description('Where money is received or paid from. Staff pick one when they record a payment; Money › Cash & bank shows the totals per account and day.')
                ->schema([
                    Forms\Components\Repeater::make('bank_accounts')->hiddenLabel()->columns(4)->reorderable()->addActionLabel('Add account')
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            Forms\Components\Hidden::make('key'),
                            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(60)->placeholder('Office cash, ENBD current…'),
                            Forms\Components\Select::make('kind')->label('Type')->options(['bank' => 'Bank account', 'cash' => 'Cash'])->default('bank')->native(false)->selectablePlaceholder(false),
                            Forms\Components\TextInput::make('bank')->label('Bank')->maxLength(60),
                            Forms\Components\TextInput::make('iban')->label('IBAN / account (optional)')->maxLength(40),
                        ]),
                ]),
            Forms\Components\Section::make('VAT report')->schema([
                Forms\Components\Select::make('tax_emirate_default')->label('Emirate of the rentals (default)')->options(self::EMIRATES)->native(false)->selectablePlaceholder(false)
                    ->helperText('A booking delivered in another emirate can say so (Rental agreement section). The VAT report groups sales by emirate.'),
            ]),
            Forms\Components\Section::make('Marketplaces')->schema([
                Forms\Components\TagsInput::make('marketplaces')->label('Websites that send you bookings')->placeholder('Add marketplace')
                    ->helperText('Picked on a booking with their reference and commission. Press Enter after each.'),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();
        $taken = [];
        $accounts = collect($d['bank_accounts'] ?? [])->filter(fn ($r) => filled($r['name'] ?? null))->map(function ($r) use (&$taken) {
            $key = filled($r['key'] ?? null) && ! in_array($r['key'], $taken, true) ? $r['key'] : Str::limit(Str::slug($r['name'], '_'), 30, '').'_'.Str::lower(Str::random(4));
            $taken[] = $key;

            return ['key' => $key, 'name' => trim($r['name']), 'kind' => ($r['kind'] ?? 'bank') === 'cash' ? 'cash' : 'bank', 'bank' => trim((string) ($r['bank'] ?? '')) ?: null, 'iban' => trim((string) ($r['iban'] ?? '')) ?: null];
        })->values()->all();
        $out = [
            'bank_accounts' => $accounts,
            'tax_emirate_default' => array_key_exists($d['tax_emirate_default'] ?? '', self::EMIRATES) ? $d['tax_emirate_default'] : 'dubai',
            'marketplaces' => array_values(array_unique(array_filter(array_map('trim', (array) ($d['marketplaces'] ?? []))))),
        ];
        Setting::write($out);
        AuditLog::create(['user_id' => auth()->id(), 'event' => 'settings', 'note' => 'Bank, cash and marketplace settings saved']);
        $this->mount();
        Notification::make()->success()->title('Saved')->send();
    }
}
