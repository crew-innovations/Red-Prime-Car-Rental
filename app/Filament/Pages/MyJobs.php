<?php

namespace App\Filament\Pages;

use App\Models\BookingJob;
use App\Support\Drivers\DriverPapers;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The driver's home screen (phone first): today's deliveries and collections,
 * what is coming up and what is done. Drivers only ever see their own jobs.
 */
class MyJobs extends Page
{
    protected static string $view = 'filament.pages.my-jobs';

    protected static ?string $slug = 'my-jobs';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'My jobs';

    protected static ?string $title = 'My jobs';

    protected static ?int $navigationSort = -20;

    #[Url]
    public string $tab = 'today';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('Driver');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = self::mine()->open()->where('scheduled_at', '<=', now()->endOfDay())->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function mine(): Builder
    {
        return BookingJob::query()
            ->where('driver_id', auth()->id())
            ->whereHas('booking', fn (Builder $q) => $q->withoutTrashed());
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['today', 'upcoming', 'done'], true) ? $tab : 'today';
    }

    public function jobs(): Collection
    {
        $q = self::mine()->with(['booking.bookable', 'booking.customer']);

        return match ($this->tab) {
            'upcoming' => $q->open()->where('scheduled_at', '>', now()->endOfDay())->orderBy('scheduled_at')->limit(50)->get(),
            'done' => $q->where('status', 'done')->where('done_at', '>=', now()->subDays(30))->orderByDesc('done_at')->limit(50)->get(),
            default => $q->open()->where('scheduled_at', '<=', now()->endOfDay())->orderBy('scheduled_at')->get(),
        };
    }

    public function summary(): array
    {
        $open = self::mine()->open();

        return [
            'today' => (clone $open)->whereBetween('scheduled_at', [today(), now()->endOfDay()])->count(),
            'moving' => (clone $open)->whereIn('status', ['on_the_way', 'arrived'])->count(),
            'handovers' => (clone $open)->where('type', 'delivery')->count(),
            'returns' => (clone $open)->where('type', 'collection')->count(),
            'late' => (clone $open)->where('scheduled_at', '<', now()->subMinutes(15))->count(),
            'upcoming' => (clone $open)->where('scheduled_at', '>', now()->endOfDay())->count(),
            'done' => self::mine()->where('status', 'done')->whereDate('done_at', today())->count(),
        ];
    }

    public function paperWarnings(): array
    {
        return DriverPapers::warnings(auth()->user());
    }
}
