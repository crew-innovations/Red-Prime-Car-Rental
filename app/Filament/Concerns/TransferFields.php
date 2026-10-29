<?php

namespace App\Filament\Concerns;

use App\Enums\ServiceType;
use App\Models\TransferService;
use App\Models\VehicleCategory;
use App\Support\Maps\GoogleMaps;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The journey part of a limousine / transfer quotation or booking (brief
 * 28 Oct-2, 4.2–4.3). Shown only for the "Limousine & Transfer" service.
 * The route is calculated by Google Maps on the server; when that is not set up
 * or fails, staff type the road distance and it is marked "manual".
 */
class TransferFields
{
    public static function isTransfer(Get $get): bool
    {
        return $get('service_type') === ServiceType::Transfer->value;
    }

    protected static function service(Get $get): ?TransferService
    {
        return $get('transfer_service_id') ? TransferService::withTrashed()->find($get('transfer_service_id')) : null;
    }

    public static function section(): Forms\Components\Section
    {
        $recalc = fn (Get $get, Set $set) => PriceFields::recalculate($get, $set);

        return Forms\Components\Section::make('Journey')
            ->icon('heroicon-o-map-pin')
            ->columns(2)
            ->visible(fn (Get $get): bool => self::isTransfer($get))
            ->schema([
                Forms\Components\Select::make('transfer_service_id')
                    ->label('Service')
                    ->options(fn (): array => TransferService::query()->active()->pluck('name', 'id')->all())
                    ->required(fn (Get $get): bool => self::isTransfer($get))
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) use ($recalc) {
                        $service = self::service($get);
                        $set('journey_type', $service?->journey_type ?? 'one_way');
                        $recalc($get, $set);
                    })
                    ->helperText(fn (): ?string => TransferService::query()->active()->exists() ? null : 'Add services under Limousine › Services first.'),
                Forms\Components\Select::make('vehicle_category_id')
                    ->label('Vehicle category')
                    ->options(fn (): array => VehicleCategory::query()->active()->get()->mapWithKeys(fn (VehicleCategory $c) => [$c->id => $c->name.($c->passengers ? ' · up to '.$c->passengers.' passengers' : '')])->all())
                    ->required(fn (Get $get): bool => self::isTransfer($get))
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) use ($recalc) {
                        $set('bookable_id', null);
                        $recalc($get, $set);
                    }),
                Forms\Components\ToggleButtons::make('journey_type')
                    ->label('Journey')
                    ->options(['one_way' => 'One way', 'return' => 'Return', 'custom' => 'Custom route'])
                    ->inline()
                    ->default('one_way')
                    ->live()
                    ->afterStateUpdated($recalc)
                    ->visible(fn (Get $get): bool => ! self::service($get)?->isHourly())
                    ->columnSpanFull(),

                Forms\Components\Select::make('pickup_search')
                    ->label('Find the pickup address')
                    ->placeholder('Type 3 letters…')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => GoogleMaps::search($search))
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        if ($state) {
                            $set('pickup_location', $state);
                            $set('route_km', null);
                        }
                    })
                    ->visible(fn (): bool => GoogleMaps::ready()),
                Forms\Components\Select::make('dropoff_search')
                    ->label('Find the destination')
                    ->placeholder('Type 3 letters…')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => GoogleMaps::search($search))
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        if ($state) {
                            $set('dropoff_location', $state);
                            $set('route_km', null);
                        }
                    })
                    ->visible(fn (Get $get): bool => GoogleMaps::ready() && ! self::service($get)?->isHourly()),
                Forms\Components\TextInput::make('dropoff_location')
                    ->label('Destination')
                    ->placeholder('Hotel, address, terminal…')
                    ->datalist(fn (): array => \App\Support\Options::list('pickup_locations'))
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => self::isTransfer($get) && (self::service($get)?->needs_destination ?? true) && ! self::service($get)?->isHourly())
                    ->live(onBlur: true)
                    ->afterStateUpdated($recalc)
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('stops')
                    ->label('Stops on the way')
                    ->schema([Forms\Components\TextInput::make('address')->hiddenLabel()->placeholder('Address of the stop')->maxLength(255)->required()])
                    ->addActionLabel('Add a stop')
                    ->defaultItems(0)
                    ->reorderable()
                    ->live()
                    ->afterStateUpdated($recalc)
                    ->visible(fn (Get $get): bool => ! self::service($get)?->isHourly())
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('return_at')
                    ->label('Return pickup')
                    ->native(false)->seconds(false)->minutesStep(5)
                    ->displayFormat('D, d M Y · H:i')
                    ->required(fn (Get $get): bool => self::isTransfer($get) && $get('journey_type') === 'return')
                    ->live(onBlur: true)
                    ->afterStateUpdated($recalc)
                    ->visible(fn (Get $get): bool => $get('journey_type') === 'return' && ! self::service($get)?->isHourly()),

                Forms\Components\Fieldset::make('Flight')
                    ->columns(3)
                    ->visible(fn (Get $get): bool => (bool) self::service($get)?->is_airport)
                    ->schema([
                        Forms\Components\TextInput::make('flight_number')->label('Flight number')->placeholder('EK 202')->maxLength(20)
                            ->helperText('Needed before the booking is confirmed.'),
                        Forms\Components\TextInput::make('airline')->maxLength(60),
                        Forms\Components\TextInput::make('terminal')->maxLength(30)->placeholder('T3'),
                    ]),

                Forms\Components\TextInput::make('luggage')->label('Bags')->numeric()->minValue(0)->maxValue(60),
                Forms\Components\TextInput::make('child_seats')->label('Child seats')->numeric()->minValue(0)->maxValue(6),
                Forms\Components\TextInput::make('waiting_minutes')->label('Waiting (minutes)')->numeric()->minValue(0)->maxValue(1440)
                    ->helperText('Planned waiting time, e.g. at a meeting.')
                    ->live(onBlur: true)->afterStateUpdated($recalc)
                    ->visible(fn (Get $get): bool => ! self::service($get)?->isHourly()),

                Forms\Components\Fieldset::make('Route')
                    ->columns(3)
                    ->visible(fn (Get $get): bool => ! self::service($get)?->isHourly())
                    ->schema([
                        Forms\Components\TextInput::make('route_km')->label('Road distance')->suffix('km')->numeric()->minValue(0.1)->maxValue(3000)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set) use ($recalc) {
                                $set('route_source', 'manual');
                                $recalc($get, $set);
                            })
                            ->helperText(fn (Get $get): string => match ($get('route_source')) {
                                'google' => 'From Google Maps.',
                                'manual' => 'Typed by hand.',
                                default => GoogleMaps::ready() ? 'Press "Calculate route".' : 'Google Maps is not set up: type the road distance.',
                            }),
                        Forms\Components\TextInput::make('route_minutes')->label('Travel time')->suffix('min')->numeric()->minValue(1)->maxValue(3000),
                        Forms\Components\TextInput::make('return_route_km')->label('Return distance')->suffix('km')->numeric()->minValue(0.1)
                            ->placeholder('Same as the way out')
                            ->live(onBlur: true)->afterStateUpdated($recalc)
                            ->visible(fn (Get $get): bool => $get('journey_type') === 'return'),
                        Forms\Components\TextInput::make('route_override_reason')->label('Why typed by hand?')
                            ->placeholder('Google Maps not set up / customer route via Hatta…')
                            ->maxLength(255)->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('route_source') === 'manual')
                            ->helperText('Needed before the booking is confirmed or the quotation is sent. Only people allowed to change prices can type a distance.'),
                        Forms\Components\Hidden::make('route_source'),
                        Forms\Components\Hidden::make('route'),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('calculateRoute')
                                ->label('Calculate route')
                                ->icon('heroicon-m-map')
                                ->disabled(fn (): bool => ! GoogleMaps::ready())
                                ->tooltip(fn (): ?string => GoogleMaps::ready() ? null : 'Set up Google Maps under Settings › Integrations')
                                ->action(function (Get $get, Set $set) use ($recalc) {
                                    $stops = collect((array) ($get('stops') ?? []))->map(fn ($s) => is_array($s) ? ($s['address'] ?? '') : $s)->filter()->values()->all();
                                    try {
                                        $r = GoogleMaps::route((string) $get('pickup_location'), (string) $get('dropoff_location'), $stops,
                                            $get('start_at') ? Carbon::parse($get('start_at')) : null);
                                        $back = null;
                                        if ($get('journey_type') === 'return' && $get('return_at')) {
                                            $back = GoogleMaps::route((string) $get('dropoff_location'), (string) $get('pickup_location'), array_reverse($stops), Carbon::parse($get('return_at')));
                                        }
                                        $set('route_km', $r['km']);
                                        $set('route_minutes', $r['minutes']);
                                        $set('return_route_km', $back['km'] ?? null);
                                        $set('route_source', 'google');
                                        $set('route_override_reason', null);
                                        // Signed by the server: a distance changed afterwards counts as typed by hand.
                                        $set('route', GoogleMaps::stamp((string) $get('pickup_location'), (string) $get('dropoff_location'), $stops, (float) $r['km'], $back ? (float) $back['km'] : null,
                                            ['meters' => $r['meters'] ?? null, 'return_meters' => $back['meters'] ?? null, 'legs' => $r['legs']]));
                                        $recalc($get, $set);
                                        Notification::make()->title($r['km'].' km · about '.$r['minutes'].' min')->success()->send();
                                    } catch (\Throwable $e) {
                                        Notification::make()->title('Route not calculated')->body($e->getMessage())->warning()->send();
                                    }
                                }),
                        ])->alignEnd()->columnSpanFull(),
                        Forms\Components\Placeholder::make('route_map')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => self::map($get))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Textarea::make('special_requests')
                    ->label('Customer requests')
                    ->placeholder('Name sign, bottled water, quiet ride…')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    protected static function map(Get $get): HtmlString
    {
        $stops = collect((array) ($get('stops') ?? []))->map(fn ($s) => is_array($s) ? ($s['address'] ?? '') : $s)->filter()->values()->all();
        $from = $get('pickup_location');
        $to = $get('dropoff_location');
        if (blank($from) || blank($to)) {
            return new HtmlString('<span class="rp-tr-muted">Enter the pickup and destination to see the route.</span>');
        }
        $link = GoogleMaps::directionsLink($from, $to, $stops);
        $embed = GoogleMaps::embedUrl($from, $to, $stops);

        return new HtmlString(($embed ? '<iframe class="rp-tr-map" src="'.e($embed).'" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Route map"></iframe>' : '')
            .'<a class="rp-tr-link" href="'.e($link).'" target="_blank" rel="noopener">Open the route in Google Maps</a>');
    }
}
