<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\DriverLocale;
use App\Models\AuditLog;
use App\Models\BookingJob;
use App\Models\DriverUnavailability;
use App\Support\Drivers\Notify;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Driver › My schedule (4 Nov, 15): the next two weeks of jobs, and the times the driver cannot
 * work. A job can't be given to them then; time that clashes with a job they already have is
 * refused (they ask the office instead).
 */
class MySchedule extends Page
{
    use DriverLocale;

    protected static string $view = 'filament.pages.my-schedule';

    protected static ?string $slug = 'driver/schedule';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = -19;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('Driver');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->isDriverOnly();
    }

    public static function getNavigationLabel(): string
    {
        \App\Support\Drivers\DriverLang::apply(auth()->user());

        return __('driver.nav_schedule');
    }

    public function getTitle(): string
    {
        return __('driver.nav_schedule');
    }

    /** @return Collection<string, array{date: Carbon, jobs: Collection, off: Collection}> */
    public function days(): Collection
    {
        $from = today();
        $to = today()->addDays(13)->endOfDay();
        $jobs = BookingJob::query()->where('driver_id', auth()->id())->open()
            ->whereBetween('scheduled_at', [$from, $to])->with('booking.bookable')->orderBy('scheduled_at')->get();
        $off = DriverUnavailability::query()->where('user_id', auth()->id())
            ->where('ends_at', '>=', $from)->where('starts_at', '<=', $to)->orderBy('starts_at')->get();

        return collect(range(0, 13))->mapWithKeys(function ($i) use ($from, $jobs, $off) {
            $d = $from->copy()->addDays($i);

            return [$d->toDateString() => [
                'date' => $d,
                'jobs' => $jobs->filter(fn ($j) => $j->scheduled_at->isSameDay($d))->values(),
                'off' => $off->filter(fn ($o) => $o->starts_at->lte($d->copy()->endOfDay()) && $o->ends_at->gte($d))->values(),
            ]];
        });
    }

    public function myOffTime(): Collection
    {
        return DriverUnavailability::query()->where('user_id', auth()->id())->where('ends_at', '>=', now())->orderBy('starts_at')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addOff')
                ->label(__('driver.add_off'))
                ->icon('heroicon-o-no-symbol')
                ->modalHeading(__('driver.add_off'))
                ->modalDescription(__('driver.add_off_desc'))
                ->modalSubmitActionLabel(__('driver.save'))
                ->fillForm(fn () => ['starts_at' => today()->addDay()->setTime(8, 0)->toDateTimeString(), 'ends_at' => today()->addDay()->setTime(20, 0)->toDateTimeString()])
                ->form([
                    Forms\Components\DateTimePicker::make('starts_at')->label(__('driver.from'))->seconds(false)->minutesStep(15)->required()->native(false),
                    Forms\Components\DateTimePicker::make('ends_at')->label(__('driver.until'))->seconds(false)->minutesStep(15)->required()->native(false)->after('starts_at'),
                    Forms\Components\Select::make('reason')->label(__('driver.reason'))->native(false)
                        ->options(collect(DriverUnavailability::REASONS)->mapWithKeys(fn ($r) => [$r => __('driver.off_'.\Illuminate\Support\Str::snake($r))])->all()),
                ])
                ->action(function (array $data, Action $action) {
                    $start = Carbon::parse($data['starts_at']);
                    $end = Carbon::parse($data['ends_at']);
                    if ($end->lt(now())) {
                        Notification::make()->title(__('driver.off_past'))->danger()->send();
                        $action->halt();
                    }
                    $clash = BookingJob::query()->where('driver_id', auth()->id())->open()
                        ->where(fn ($q) => $q->whereBetween('scheduled_at', [$start, $end])
                            ->orWhere(fn ($w) => $w->whereNotNull('ends_at')->where('scheduled_at', '<', $end)->where('ends_at', '>', $start)))
                        ->with('booking')->first();
                    if ($clash) {
                        Notification::make()->title(__('driver.off_clash', ['ref' => $clash->booking?->reference]))->danger()->persistent()->send();
                        $action->halt();
                    }
                    $off = DriverUnavailability::create(['user_id' => auth()->id(), 'starts_at' => $start, 'ends_at' => $end,
                        'reason' => in_array($data['reason'] ?? null, DriverUnavailability::REASONS, true) ? $data['reason'] : null, 'created_by' => auth()->id()]);
                    Notify::staff('manage-drivers', auth()->user()->name.' is not available', $off->label(), \App\Filament\Pages\DispatchBoard::getUrl(['day' => $start->toDateString()]), null, 'info', event: 'other');
                    Notification::make()->title(__('driver.off_saved'))->success()->send();
                }),
        ];
    }

    public function removeOff(int $id): void
    {
        $off = DriverUnavailability::query()->where('user_id', auth()->id())->findOrFail($id);
        $off->delete();
        AuditLog::note($off, 'driver', auth()->user()->name.' removed not-available time: '.$off->label());
        Notification::make()->title(__('driver.off_removed'))->success()->send();
    }
}
