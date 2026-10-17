<?php

namespace App\Filament\Pages;

use App\Enums\LeadStatus;
use App\Enums\Pipeline;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/** Sales pipelines (Schedule A3) as a drag-and-drop board. */
class PipelineBoard extends Page
{
    protected static string $view = 'filament.pages.pipeline-board';

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?string $title = 'Sales pipeline';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'pipeline';

    #[Url]
    public string $pipeline = 'car';

    #[Url]
    public bool $mine = false;

    /** Won / final stages only show leads moved there in the last 30 days. */
    protected const RECENT_DAYS = 30;

    public static function canAccess(): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    public function mount(): void
    {
        if (! Pipeline::tryFrom($this->pipeline)) {
            $this->pipeline = Pipeline::Car->value;
        }
    }

    public function getPipeline(): Pipeline
    {
        return Pipeline::tryFrom($this->pipeline) ?? Pipeline::Car;
    }

    public function setPipeline(string $pipeline): void
    {
        $this->pipeline = Pipeline::tryFrom($pipeline)?->value ?? Pipeline::Car->value;
    }

    /** @return array<string, array{label: string, won: bool, leads: Collection, budget: float}> */
    public function getColumns(): array
    {
        $pipeline = $this->getPipeline();

        $leads = Lead::query()
            ->with(['assignee', 'interestedIn', 'leadSource'])
            ->where('pipeline', $pipeline->value)
            ->where('stage', '<>', Pipeline::LOST)
            ->when($this->mine, fn ($q) => $q->where('assigned_to', auth()->id()))
            ->where(fn ($q) => $q->where('status', '<>', LeadStatus::Converted)->orWhere('updated_at', '>=', now()->subDays(self::RECENT_DAYS)))
            ->orderByRaw("CASE priority WHEN 'hot' THEN 0 WHEN 'warm' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get()
            ->groupBy('stage');

        $columns = [];
        foreach ($pipeline->stages() as $key => $label) {
            $items = $leads->get($key, collect());
            $columns[$key] = [
                'label' => $label,
                'won' => $pipeline->isWon($key),
                'leads' => $items,
                'budget' => (float) $items->sum('budget'),
            ];
        }

        return $columns;
    }

    public function getCounts(): array
    {
        return Lead::query()
            ->open()
            ->when($this->mine, fn ($q) => $q->where('assigned_to', auth()->id()))
            ->selectRaw('pipeline, count(*) as total')
            ->groupBy('pipeline')
            ->pluck('total', 'pipeline')
            ->all();
    }

    public function moveLead(int $id, string $stage): void
    {
        if (! \App\Support\Access::allows('manage-leads')) {
            Notification::make()->title('You can view the pipeline but not move leads.')->warning()->send();

            return;
        }

        $lead = Lead::find($id);
        $pipeline = $this->getPipeline();

        if (! $lead || $lead->pipeline !== $pipeline || ! $pipeline->hasStage($stage) || $lead->stage === $stage) {
            return;
        }

        if ($stage === Pipeline::LOST) {
            $this->mountAction('markLost', ['lead' => $id]);

            return;
        }

        $wasWon = $lead->status === LeadStatus::Converted;
        $lead->update(['stage' => $stage]);

        $note = Notification::make()
            ->title($lead->name.' → '.$pipeline->stageLabel($stage))
            ->success();

        if (! $wasWon && $lead->status === LeadStatus::Converted && $pipeline !== Pipeline::B2b && $lead->bookings()->doesntExist()) {
            $note->body('Deal won. Create the booking to hold the car / yacht.')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('book')
                        ->label('Create booking')
                        ->button()
                        ->url(\App\Filament\Resources\BookingResource::getUrl('create', ['lead' => $lead->id])),
                ]);
        }

        $note->send();
    }

    public function markLostAction(): Action
    {
        return Action::make('markLost')
            ->modalHeading('Mark as lost')
            ->modalWidth('md')
            ->form([
                Forms\Components\TextInput::make('lost_reason')
                    ->validationMessages(['required' => 'Please add why this lead was lost.'])
                    ->label('Why was it lost?')
                    ->datalist(\App\Support\Options::list('lost_reasons'))
                    ->required(),
            ])
            ->authorize('manage-leads')
            ->action(function (array $data, array $arguments): void {
                Lead::find($arguments['lead'] ?? null)?->update(['stage' => Pipeline::LOST, 'lost_reason' => $data['lost_reason']]);

                Notification::make()->title('Lead marked as lost')->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label('List view')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(LeadResource::getUrl('index')),
            Action::make('create')
                ->label('Add lead')
                ->icon('heroicon-o-plus')
                ->url(LeadResource::getUrl('create')),
        ];
    }
}
