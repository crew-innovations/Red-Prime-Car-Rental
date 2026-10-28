<?php

namespace App\Filament\Pages;

use App\Enums\BookingStatus;
use App\Models\User;
use App\Support\Access;
use App\Support\Schedule\CalendarFeed;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Calendar (brief 28 Oct-2, Phase 9): day, week, month and agenda views of
 * handovers, returns, charters, transfers, departures, follow-ups and quotes,
 * with filters. Drivers see only their own jobs; agents their own work.
 */
class Calendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Bookings & drivers';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Calendar';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.calendar';

    public const VIEWS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'agenda' => 'Agenda'];

    #[Url(as: 'view')]
    public string $mode = 'week';

    #[Url]
    public ?string $date = null;

    #[Url]
    public ?string $agent = null;

    #[Url]
    public ?string $driver = null;

    #[Url]
    public ?string $service = null;

    #[Url]
    public ?string $item = null;

    #[Url]
    public ?string $status = null;

    #[Url]
    public ?string $kind = null;

    #[Url]
    public bool $problems = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasCrmRole() || Access::isDriverOnly());
    }

    /** Drivers find it next to their jobs. */
    public static function getNavigationGroup(): ?string
    {
        return Access::isDriverOnly() ? null : static::$navigationGroup;
    }

    public static function getNavigationSort(): ?int
    {
        return Access::isDriverOnly() ? -18 : static::$navigationSort;
    }

    public function mount(): void
    {
        $this->mode = array_key_exists($this->mode, self::VIEWS) ? $this->mode : 'week';
        $this->date = rescue(fn () => Carbon::parse($this->date)->toDateString(), today()->toDateString(), false);
        if (Access::isDriverOnly()) {
            $this->driver = (string) auth()->id();
            if ($this->mode === 'week' && ! request()->has('view')) {
                $this->mode = 'agenda';
            }
        }
    }

    public function anchor(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->date), today(), false)->startOfDay();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(): array
    {
        $d = $this->anchor();

        return match ($this->mode) {
            'day' => [$d->copy(), $d->copy()->endOfDay()],
            'month' => [$d->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $d->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)],
            'agenda' => [$d->copy(), $d->copy()->addDays(13)->endOfDay()],
            default => [$d->copy()->startOfWeek(Carbon::MONDAY), $d->copy()->endOfWeek(Carbon::SUNDAY)],
        };
    }

    public function move(int $step): void
    {
        $d = $this->anchor();
        $next = match ($this->mode) {
            'day' => $d->addDays($step),
            'month' => $d->addMonthsNoOverflow($step),
            'agenda' => $d->addDays(14 * $step),
            default => $d->addWeeks($step),
        };
        $this->date = $next->toDateString();
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
    }

    public function openDay(string $date): void
    {
        $this->date = $date;
        $this->mode = 'day';
    }

    public function clearFilters(): void
    {
        $this->agent = $this->service = $this->item = $this->status = $this->kind = null;
        $this->problems = false;
        if (! Access::isDriverOnly()) {
            $this->driver = null;
        }
    }

    public function events(): Collection
    {
        [$from, $to] = $this->range();
        $filters = array_filter([
            'agent' => $this->agent, 'driver' => Access::isDriverOnly() ? (string) auth()->id() : $this->driver,
            'service' => $this->service, 'item' => $this->item, 'status' => $this->status, 'kind' => $this->kind, 'problems' => $this->problems,
        ]);
        $events = (new CalendarFeed($from, $to, $filters))->events();

        if (Access::isDriverOnly()) {
            $events = $events->map(fn ($e) => ['url' => MyJobs::getUrl(), 'sub' => preg_replace('/ · Agent: [^·]+/', '', $e['sub'])] + $e);
        }

        return $events;
    }

    /** Filter choices. */
    public function options(): array
    {
        $agents = Access::agentScoped() || Access::isDriverOnly() ? [] : User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['Sales', 'Agent', 'Manager', 'Admin', 'Operations']))->orderBy('name')->pluck('name', 'id')->all();
        $drivers = Access::isDriverOnly() ? [] : User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Driver'))->orderBy('name')->pluck('name', 'id')->all();
        $items = collect()
            ->merge(\App\Models\Car::query()->orderBy('name')->get(['id', 'name'])->mapWithKeys(fn ($c) => ['car:'.$c->id => 'Car · '.$c->name]))
            ->merge(\App\Models\Yacht::query()->orderBy('name')->get(['id', 'name'])->mapWithKeys(fn ($y) => ['yacht:'.$y->id => 'Yacht · '.$y->name]))
            ->all();

        return [
            'agents' => $agents,
            'drivers' => $drivers,
            'items' => $items,
            'services' => collect(\App\Enums\ServiceType::cases())->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all(),
            'statuses' => collect([BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed, BookingStatus::Cancelled])->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all(),
            'kinds' => CalendarFeed::KINDS,
        ];
    }

    public function heading(): string
    {
        [$from, $to] = $this->range();

        return match ($this->mode) {
            'day' => $from->format('l, j F Y'),
            'month' => $this->anchor()->format('F Y'),
            'agenda' => $from->format('j M').' – '.$to->format('j M Y'),
            default => $from->format('j M').' – '.$to->format('j M Y'),
        };
    }
}
