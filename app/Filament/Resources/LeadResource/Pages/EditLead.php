<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\Pipeline;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\Phone;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use App\Filament\Concerns\GuardsSave;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    use \App\Filament\Concerns\HasNextStep;

    use GuardsSave {
        save as guardedSave;
        getFormActions as guardedFormActions;
    }

    protected static string $resource = LeadResource::class;

    /** 5 Nov (S3): nobody but the owner (or Admin) saves a lead; a waiting lead must be accepted first. */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_if(LeadResource::waitingForAgent($this->getRecord()), 403);

        $this->guardedSave($shouldRedirect, $shouldSendSavedNotification);
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return $this->progressBar($this->detailsLine()); // 6 Nov (S4): progress bar + the details line
    }

    protected function detailsLine(): ?string
    {
        $lead = $this->getRecord();

        return LeadResource::waitingForAgent($lead)
            ? 'Waiting for an agent. The first to accept owns it.'
            : null;
    }

    protected function getFormActions(): array
    {
        return LeadResource::waitingForAgent($this->getRecord()) ? [$this->getCancelFormAction()->label('Back')] : $this->guardedFormActions();
    }

    /** 20 Nov (item 11): a lead with a customer keeps the customer's contact details. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = \App\Support\CustomStatuses::take($data, 'lead', 'stage', $this->getRecord()->stage, true, $this->getRecord());

        return filled($data['customer_id'] ?? null) ? LeadResource::withCustomer($data) : $data;
    }

    protected function getHeaderActions(): array
    {
        return $this->withNextStep($this->pageActions()); // 6 Nov (S4): one big next step, the rest under "More"
    }

    /** Every button of this page (the next step is picked from these). */
    /** The Instagram / Messenger / WhatsApp conversation of this lead, if this person may open it. */
    protected function socialThread(): ?\App\Models\ChatThread
    {
        return once(fn () => \App\Support\Chat\Chat::visible()->where('kind', 'social')->where('lead_id', $this->getRecord()->id)->latest('last_message_at')->first());
    }

    /** 6 Nov (limousine L4): price a ride for every class in seconds, then send the customer a booking link. */
    protected function limoPriceAction(): Actions\Action
    {
        $services = fn () => \App\Models\TransferService::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'slug')->all();
        // What was typed is always an option (works without Google too), then Google's suggestions.
        $places = function (string $search): array {
            $search = trim($search);
            $found = collect(\App\Support\Maps\GoogleMaps::search($search))
                ->merge(collect(\App\Support\Transfers\StarterCatalog::SPOTS)->filter(fn ($s) => str_contains(mb_strtolower($s), mb_strtolower($search))));

            return collect($search !== '' ? [$search] : [])->merge($found)->unique()->take(10)->mapWithKeys(fn ($s) => [$s => $s])->all();
        };
        $spots = array_combine(\App\Support\Transfers\StarterCatalog::SPOTS, \App\Support\Transfers\StarterCatalog::SPOTS);

        return Actions\Action::make('limoPrice')
            ->label('Quick limousine price')
            ->icon('heroicon-o-map')
            ->modalHeading('Quick limousine price')
            ->modalDescription('Type the trip: every class is priced at once. Then send the customer a booking link; their booking joins this lead.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Send booking link on WhatsApp')
            ->fillForm(fn (): array => ['service' => array_key_first($services()), 'date' => now()->addDay()->toDateString(), 'time' => '10:00', 'passengers' => $this->getRecord()->guests ?: 2])
            ->form(fn (): array => [
                \Filament\Forms\Components\Grid::make(2)->schema([
                    \Filament\Forms\Components\Select::make('service')->label('Service')->options($services)->required()->live()->native(false),
                    \Filament\Forms\Components\TextInput::make('passengers')->numeric()->minValue(1)->maxValue(60)->live(onBlur: true),
                    \Filament\Forms\Components\Select::make('pickup')->label('Pickup')->options($spots)->searchable()->getSearchResultsUsing($places)
                        ->getOptionLabelUsing(fn ($value) => $value)->required()->live(),
                    \Filament\Forms\Components\Select::make('dropoff')->label('Drop-off')->options($spots)->searchable()->getSearchResultsUsing($places)
                        ->getOptionLabelUsing(fn ($value) => $value)->live(),
                    \Filament\Forms\Components\DatePicker::make('date')->native(false)->minDate(today())->required()->live(),
                    \Filament\Forms\Components\TimePicker::make('time')->seconds(false)->required()->live(),
                ]),
                \Filament\Forms\Components\Placeholder::make('prices')->hiddenLabel()
                    ->content(fn (\Filament\Forms\Get $get) => new \Illuminate\Support\HtmlString(self::limoPrices($get))),
                \Filament\Forms\Components\Radio::make('class')->label('Class to send')->inline()
                    ->options(fn () => \App\Models\VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'slug')->all()),
            ])
            ->action(function (array $data) {
                $lead = $this->getRecord();
                $url = \App\Support\Transfers\LimoLink::url($lead, ['date' => filled($data['date'] ?? null) ? \Illuminate\Support\Carbon::parse($data['date'])->format('Y-m-d') : null, 'time' => substr((string) ($data['time'] ?? ''), 0, 5)] + $data);
                $phone = preg_replace('/\D+/', '', (string) ($lead->whatsapp ?: $lead->phone));
                $text = 'Hello '.strtok((string) $lead->name, ' ').'! Here is your RED PRIME chauffeur price. Check the details and book here: '.$url;
                \App\Models\LeadActivity::create(['lead_id' => $lead->id, 'user_id' => auth()->id(), 'type' => 'whatsapp', 'body' => 'Sent a limousine booking link.']);
                $this->js('window.open('.json_encode($phone ? 'https://wa.me/'.$phone.'?text='.rawurlencode($text) : $url).', "_blank")');
            });
    }

    /** The class prices for the quick-price window (same engine as the website). */
    protected static function limoPrices(\Filament\Forms\Get $get): string
    {
        if (blank($get('service')) || blank($get('pickup')) || blank($get('date')) || blank($get('time'))) {
            return '<p class="text-sm text-gray-500">Pick the service, pickup, date and time to see the prices.</p>';
        }
        $at = \Illuminate\Support\Carbon::parse($get('date'))->format('Y-m-d').' '.substr((string) $get('time'), 0, 5);
        $first = \App\Models\VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->value('slug');
        try {
            $r = \App\Http\Controllers\Api\V1\TransferQuote::quote(\Illuminate\Http\Request::create('/', 'POST', array_filter([
                'service' => $get('service'), 'product_slug' => $first, 'pickup' => $get('pickup'), 'dropoff' => $get('dropoff'),
                'start_at' => $at, 'end_at' => \Illuminate\Support\Carbon::parse($at)->addHours(5)->format('Y-m-d H:i'),
                'passengers' => (int) $get('passengers') ?: null, 'all_classes' => true,
            ])));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return '<p class="text-sm" style="color:rgb(var(--danger-600))">'.e(collect($e->errors())->flatten()->first()).'</p>';
        }
        if (empty($r['classes'])) {
            $why = \App\Support\Maps\GoogleMaps::ready()
                ? 'No price for this trip yet: check the per-km rate in Limousine › Transfer rates, or price it by hand in a quotation.'
                : 'No price yet: Google Maps is not connected (Settings › Integrations), so the road distance is unknown.';

            return '<p class="text-sm text-gray-500">'.e($why).' You can still send the link: the website prices it again for the customer.</p>';
        }
        $km = isset($r['route']['km']) ? '<p class="text-sm text-gray-500" style="margin-bottom:.4rem">'.e($r['route']['km']).' km · '.e($r['route']['minutes'] ?? '–').' min by road</p>' : '';
        $rows = collect($r['classes'])->map(fn ($c) => '<tr style="'.($c['fits'] ? '' : 'opacity:.45').'"><td style="padding:.3rem .6rem">'.e($c['name']).($c['fits'] ? '' : ' <small>(too small)</small>').'</td><td style="padding:.3rem .6rem;text-align:right;font-weight:600">'.($c['total'] ? 'AED '.number_format($c['total'], 0) : '–').'</td></tr>')->implode('');

        return $km.'<table style="width:100%;border-collapse:collapse">'.$rows.'</table>';
    }

    protected function pageActions(): array
    {
        /** @var Lead $lead */
        $lead = $this->getRecord();

        // 5 Nov (S3): an agent sees a waiting lead with one button, "Accept this lead".
        if (LeadResource::waitingForAgent($lead)) {
            return [
                Actions\Action::make('claimLead')
                    ->label('Accept this lead')
                    ->icon('heroicon-o-hand-raised')
                    ->color('danger')
                    ->size('lg')
                    ->action(function () {
                        [$won, $owner] = \App\Support\Leads\LeadBroadcast::claim($this->getRecord(), auth()->user(), 'crm', request()->ip());
                        if ($won) {
                            Notification::make()->title('The lead is yours')->body('Call or message them now.')->success()->send();
                            $this->redirect(LeadResource::getUrl('edit', ['record' => $this->getRecord()]));

                            return;
                        }
                        Notification::make()->title($owner ? 'Taken by '.$owner->name : 'Could not accept this lead')->danger()->send();
                        $this->redirect(LeadResource::getUrl('index'));
                    }),
            ];
        }

        return [
            $this->limoPriceAction(), // 6 Nov (limousine L4)
            // 12 Nov: the lead came from an Instagram / Messenger / WhatsApp message: carry on that chat.
            Actions\Action::make('openChat')->label(fn (): string => 'Chat on '.($this->socialThread()?->channelLabel() ?? 'social'))->icon('heroicon-o-chat-bubble-left-right')->color('gray')
                ->visible(fn (): bool => (bool) $this->socialThread())
                ->badge(fn () => ($t = $this->socialThread()) && ($n = \App\Support\Chat\Chat::unread(null, $t)) ? $n : null)
                ->url(fn (): ?string => ($t = $this->socialThread()) ? \App\Filament\Pages\Messages::getUrl(['thread' => $t->id]) : null),
            \App\Filament\Support\EmailActions::send(Actions\Action::class),
            Actions\Action::make('acceptLead')
                ->label('Accept lead')
                ->icon('heroicon-o-hand-raised')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->assignment_status === 'pending' && (int) $this->getRecord()->assigned_to === (int) auth()->id())
                ->action(function () {
                    $this->getRecord()->acceptAssignment();
                    Notification::make()->title('The lead is yours')->success()->send();
                    $this->redirect(LeadResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Actions\Action::make('handBack')
                ->label('Hand back')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => (int) $this->getRecord()->assigned_to === (int) auth()->id() && ! \App\Support\Access::allows('assign-leads'))
                ->form([Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200)])
                ->modalDescription('The lead goes back to the managers to give to someone else.')
                ->action(function (array $data) {
                    $this->getRecord()->declineAssignment($data['reason']);
                    Notification::make()->title('Lead handed back')->success()->send();
                    $this->redirect(LeadResource::getUrl('index'));
                }),
            Actions\Action::make('whatsapp')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->url(Phone::whatsappUrl($lead->whatsapp ?: $lead->phone, \App\Support\Options::message('msg_greeting', ['name' => $lead->name])))
                ->openUrlInNewTab()
                ->visible(filled($lead->whatsapp ?: $lead->phone)),
            Actions\Action::make('call')
                ->label('Call')
                ->icon('heroicon-o-phone')
                ->color('gray')
                ->url(Phone::telUrl($lead->phone))
                ->visible(filled($lead->phone)),
            Actions\Action::make('quote')
                ->label('Create quotation')
                ->authorize('manage-quotations')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn (): string => \App\Filament\Resources\QuotationResource::getUrl('create', ['lead' => $this->getRecord()->id]))
                ->visible(fn (): bool => $this->getRecord()->stage !== Pipeline::LOST && ! $this->getRecord()->hasLiveBooking()),
            Actions\Action::make('book')
                ->label('Create booking')
                ->authorize('manage-bookings')
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->url(fn (): string => BookingResource::getUrl('create', ['lead' => $this->getRecord()->id]))
                ->visible(fn (): bool => $this->getRecord()->pipeline !== Pipeline::B2b && $this->getRecord()->stage !== Pipeline::LOST && ! $this->getRecord()->hasLiveBooking()),
            LeadResource::customerProfileAction(Actions\Action::make('convert')) // 20 Nov (item 9): same customer form as New Booking
                ->color('gray')
                ->record(fn () => $this->getRecord())
                ->visible(fn (): bool => ! $this->getRecord()->customer_id)
                ->after(fn () => $this->redirect(LeadResource::getUrl('edit', ['record' => $this->getRecord()]))),
            Actions\ActionGroup::make([
                \App\Filament\Support\ReassignAction::make(Actions\Action::class)
                    ->visible(fn (): bool => (bool) $this->getRecord()->assigned_to || (bool) $this->getRecord()->customer_id),
                Actions\Action::make('lost')
                    ->label('Mark as lost')
                    ->authorize('manage-leads')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('lost_reason')
                            ->validationMessages(['required' => 'Please add why this lead was lost.'])
                            ->label('Reason')
                            ->datalist(\App\Support\Options::list('lost_reasons'))
                            ->required(),
                    ])
                    ->visible(fn (): bool => $this->getRecord()->stage !== Pipeline::LOST)
                    ->action(function (array $data) {
                        $this->getRecord()->update(['stage' => Pipeline::LOST, 'lost_reason' => $data['lost_reason']]);
                        $this->refreshFormData(['stage', 'status', 'lost_reason']);
                    }),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
