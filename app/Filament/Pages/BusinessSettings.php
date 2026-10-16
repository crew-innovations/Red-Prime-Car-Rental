<?php

namespace App\Filament\Pages;

use App\Enums\DocumentType;
use App\Models\Setting;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Business rules the owner decides: company details for quotations, VAT,
 * quotation defaults and which documents each booking type needs.
 * Nothing here is pre-filled with assumed values.
 */
class BusinessSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.business-settings';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Business settings';

    protected static ?string $title = 'Business settings';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'business-settings';

    public ?array $data = [];

    public const KINDS = [
        'self_drive' => 'Car, self-drive',
        'with_driver' => 'Car, with driver',
        'yacht' => 'Yacht charter',
        'trip' => 'Yacht trip',
        'corporate' => 'Extra for company / agency customers',
    ];

    public static function canAccess(): bool
    {
        return Access::allows('manage-team');
    }

    public function mount(): void
    {
        $values = Setting::values();
        foreach (array_keys(self::KINDS) as $kind) {
            $values['req_'.$kind] = $values['doc_requirements'][$kind] ?? [];
        }
        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        $docTypes = DocumentType::requestable();

        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Company details')
                    ->description('Shown on quotations sent to customers.')
                    ->icon('heroicon-o-building-office-2')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('company_name')->required()->maxLength(120),
                        Forms\Components\TextInput::make('company_trn')->label('VAT registration number (TRN)')->maxLength(30),
                        Forms\Components\TextInput::make('company_phone')->label('Phone / WhatsApp')->tel()->maxLength(30),
                        Forms\Components\TextInput::make('company_email')->label('Email')->email()->maxLength(120),
                        Forms\Components\Textarea::make('company_address')->label('Address')->rows(2),
                        Forms\Components\Textarea::make('bank_details')->label('Bank details for payment')->rows(3),
                    ]),

                Forms\Components\Section::make('VAT')
                    ->description('Leave off until your accountant confirms the rate and how prices are entered.')
                    ->icon('heroicon-o-receipt-percent')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Toggle::make('vat_enabled')->label('Charge VAT')->live()->inline(false),
                        Forms\Components\TextInput::make('vat_rate')
                            ->label('VAT rate')
                            ->numeric()->minValue(0.01)->maxValue(100)->suffix('%')
                            ->required(fn (Get $get): bool => (bool) $get('vat_enabled'))
                            ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                        Forms\Components\Toggle::make('vat_inclusive')
                            ->label('Prices already include VAT')
                            ->inline(false)
                            ->visible(fn (Get $get): bool => (bool) $get('vat_enabled')),
                    ]),

                Forms\Components\Section::make('Quotations')
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('quote_valid_days')
                            ->label('Default validity')
                            ->numeric()->minValue(1)->maxValue(365)->suffix('days')
                            ->helperText('Empty = staff choose the date on each quotation.'),
                        Forms\Components\Textarea::make('quote_terms')
                            ->label('Standard terms')
                            ->helperText('Added to new quotations; staff can edit per quotation.')
                            ->rows(5)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Customer documents')
                    ->description('Which documents each booking needs. Nothing is required until you choose here.')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
                        ...collect(self::KINDS)->map(fn ($label, $kind) => Forms\Components\CheckboxList::make('req_'.$kind)
                            ->label($label)
                            ->options($docTypes)
                            ->columns(2))->values()->all(),
                        Forms\Components\Toggle::make('doc_block_handover')
                            ->label('Block hand over / start until required documents are approved')
                            ->helperText('Managers can still override; the override is recorded.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('upload_link_hours')
                            ->label('Upload links expire after')
                            ->numeric()->minValue(1)->maxValue(720)->suffix('hours')
                            ->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $requirements = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $requirements[$kind] = array_values($data['req_'.$kind] ?? []);
            unset($data['req_'.$kind]);
        }
        $data['doc_requirements'] = $requirements;
        if (empty($data['vat_enabled'])) {
            $data['vat_rate'] = null;
            $data['vat_inclusive'] = false;
        }

        Setting::write($data);
        \App\Models\AuditLog::create(['user_id' => auth()->id(), 'event' => 'settings', 'new_values' => $data, 'note' => 'Business settings updated']);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
