<?php

namespace App\Filament\Pages;

use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Filament\Concerns\PriceFields;
use App\Filament\Resources\BookingResource;
use App\Support\Access;
use App\Support\Pricing\PriceEngine;
use App\Support\Tax;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Pages\Page;

/**
 * Pricing › Price check: what the price list gives for a car / yacht / trip,
 * dates and extras, with the full breakdown. Nothing is saved.
 */
class PriceCheck extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Price check';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.price-check';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && ! Access::isCustomer() && (Access::allows('manage-quotations') || Access::allows('manage-bookings') || Access::allows('manage-pricing'));
    }

    public function getSubheading(): ?string
    {
        return 'Try a price without making a quotation. Uses the same calculation as quotations, bookings and the website.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'service_type' => ServiceType::Car->value,
            'start_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            'end_at' => now()->addDays(4)->setTime(10, 0)->toDateTimeString(),
            'drive_mode' => DriveMode::SelfDrive->value,
            'delivery_method' => 'office',
            'return_method' => 'office',
        ]);
    }

    public function form(Form $form): Form
    {
        $isCar = fn (Get $get): bool => $get('service_type') === ServiceType::Car->value;

        return $form
            ->statePath('data')
            ->columns(2)
            ->schema([
                Forms\Components\ToggleButtons::make('service_type')
                    ->label('Service')
                    ->options(collect(ServiceType::pickerOptions(ServiceType::Yacht))->except(ServiceType::Transfer->value)->all()) // transfers: priced on the quotation (route needed)
                    ->icons(ServiceType::pickerIcons())
                    ->inline()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('bookable_id', null))
                    ->columnSpanFull(),
                Forms\Components\Select::make('bookable_id')
                    ->label(fn (Get $get): string => BookingResource::itemLabel($get('service_type')))
                    ->options(fn (Get $get): array => BookingResource::itemOptions($get('service_type')))
                    ->searchable()->live()
                    ->columnSpanFull(),
                Forms\Components\ToggleButtons::make('drive_mode')->label('Rental type')->options(DriveMode::class)->inline()->live()->visible($isCar),
                Forms\Components\TextInput::make('guests')->numeric()->minValue(1)->live(onBlur: true)->visible(fn (Get $get): bool => ! $isCar($get)),
                Forms\Components\DateTimePicker::make('start_at')->label('Start')->native(false)->seconds(false)->minutesStep(15)->live(onBlur: true),
                Forms\Components\DateTimePicker::make('end_at')->label('End')->native(false)->seconds(false)->minutesStep(15)->after('start_at')->live(onBlur: true),
                ...PriceFields::handoverChoice(),
                Forms\Components\CheckboxList::make('extra_keys')
                    ->label('Extras')
                    ->options(fn (Get $get): array => collect(PriceEngine::optionalExtras($get('service_type'), BookingResource::findItem($get('service_type'), $get('bookable_id'))))
                        ->map(fn (array $o) => $o['label'].' · AED '.number_format($o['price'], 2).' '.(PriceEngine::EXTRA_UNITS[$o['unit']] ?? ''))->all())
                    ->columns(2)->live()
                    ->columnSpanFull(),
            ]);
    }

    /** Called by the view; the form state already holds the inputs. */
    public function result(): ?array
    {
        $state = $this->data ?? [];
        $get = fn (string $key) => $state[$key] ?? null;

        $result = PriceEngine::calculate([
            'service_type' => $get('service_type'),
            'item' => BookingResource::findItem($get('service_type'), $get('bookable_id')),
            'start_at' => $get('start_at'),
            'end_at' => $get('end_at'),
            'drive_mode' => $get('drive_mode'),
            'guests' => (int) $get('guests'),
            'delivery_method' => $get('service_type') === 'car' ? $get('delivery_method') : null,
            'return_method' => $get('service_type') === 'car' ? $get('return_method') : null,
            'extra_keys' => (array) ($get('extra_keys') ?? []),
        ]);
        if (! $result) {
            return null;
        }
        $totals = PriceEngine::totals($result['base_amount'], $result['lines'], 0, Tax::defaultRate(), Tax::defaultInclusive());

        return $result + ['totals' => $totals];
    }
}
