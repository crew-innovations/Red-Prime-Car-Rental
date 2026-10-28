<?php

namespace App\Support\Schedule;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\QuotationResource;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\Lead;
use App\Models\PackageDeparture;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\TripDate;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What happens when, for the calendar and the dashboard (brief 28 Oct-2, Phase 9).
 * Built from the real records: bookings (car handover / return, charters,
 * transfers), driver and crew jobs, trip and package departures, lead follow-ups
 * and quotations about to expire. Drivers only get their own jobs; agents only
 * their own bookings, leads and quotations.
 *
 * Each event: key, kind, title, sub, start, end, tone, url, status, agent_id,
 * driver_ids, item, service, needs_driver, conflict.
 */
class CalendarFeed
{
    public const KINDS = ['out' => 'Handover / pickup', 'back' => 'Return / collection', 'charter' => 'Charter', 'transfer' => 'Transfer',
        'departure' => 'Trip departure', 'followup' => 'Follow-up', 'quote' => 'Quotation expires'];

    public function __construct(public Carbon $from, public Carbon $to, public array $filters = [], public ?User $user = null)
    {
        $this->user ??= auth()->user();
    }

    public function events(): Collection
    {
        $driverOnly = $this->user && Access::isDriverOnly();
        $events = $this->bookingEvents();
        if (! $driverOnly) {
            $events = $events->concat($this->departures())->concat($this->followUps())->concat($this->quotes());
        }

        $events = $this->markConflicts($events);

        // Filters.
        $f = $this->filters;
        $events = $events
            ->when($driverOnly, fn ($c) => $c->filter(fn ($e) => in_array($this->user->id, $e['driver_ids'], true)))
            ->when(filled($f['driver'] ?? null), fn ($c) => $c->filter(fn ($e) => in_array((int) $f['driver'], $e['driver_ids'], true)))
            ->when(filled($f['agent'] ?? null), fn ($c) => $c->filter(fn ($e) => (int) $e['agent_id'] === (int) $f['agent']))
            ->when(filled($f['service'] ?? null), fn ($c) => $c->filter(fn ($e) => $e['service'] === $f['service']))
            ->when(filled($f['item'] ?? null), fn ($c) => $c->filter(fn ($e) => $e['item'] === $f['item']))
            ->when(filled($f['status'] ?? null), fn ($c) => $c->filter(fn ($e) => $e['status'] === $f['status']))
            ->when(filled($f['kind'] ?? null), fn ($c) => $c->filter(fn ($e) => $e['kind'] === $f['kind']))
            ->when(! empty($f['problems']), fn ($c) => $c->filter(fn ($e) => $e['conflict'] || $e['needs_driver']));

        return $events->sortBy(fn ($e) => $e['start']->timestamp)->values();
    }

    /* ------------------------------------------------------------------ */

    protected function bookingEvents(): Collection
    {
        $statuses = filled($this->filters['status'] ?? null)
            ? [BookingStatus::tryFrom($this->filters['status'])]
            : [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed];

        $query = Booking::query()->with(['bookable', 'customer', 'driver', 'assignee'])
            ->whereIn('status', array_filter($statuses))
            ->whereIn('service_type', array_filter([ServiceType::Car, ServiceType::Yacht, ServiceType::tryFrom('transfer')]))
            ->where('start_at', '<=', $this->to)
            ->where('end_at', '>=', $this->from);
        if ($this->user && ! Access::isDriverOnly()) {
            $query = Access::scopeOwn($query, 'booking');
        }

        $out = collect();
        foreach ($query->get() as $b) {
            /** @var Booking $b */
            $base = [
                'status' => $b->status?->value,
                'agent_id' => $b->assigned_to,
                'item' => $b->bookable_type.':'.$b->bookable_id,
                'service' => $b->service_type?->value,
                'url' => BookingResource::getUrl('edit', ['record' => $b]),
                'conflict' => false,
                'statusLabel' => $b->status?->getLabel(),
            ];
            $who = collect([$b->customer?->name, $b->assignee?->name ? 'Agent: '.$b->assignee->name : null]);

            if ($b->service_type === ServiceType::Car) {
                $withDriver = $b->drive_mode === DriveMode::WithDriver;
                $outNeeds = $withDriver || $b->delivery_method === 'delivery';
                $backNeeds = $withDriver || in_array($b->return_method, ['collection'], true);
                $outDriver = $b->driver_id;
                $backDriver = $withDriver ? $b->driver_id : ($b->return_driver_id ?: $b->driver_id);

                if ($b->start_at->between($this->from, $this->to)) {
                    $out->push($base + [
                        'key' => 'b'.$b->id.'-out', 'kind' => 'out',
                        'title' => ($withDriver ? 'Chauffeur: ' : ($b->delivery_method === 'delivery' ? 'Delivery: ' : 'Handover: ')).($b->bookable?->name ?? 'Car'),
                        'sub' => $who->push($b->pickup_location)->push($outNeeds ? ($outDriver ? 'Driver: '.$this->name($outDriver) : 'No driver yet') : null)->filter()->implode(' · '),
                        'start' => $b->start_at, 'end' => $withDriver ? $b->end_at : $b->start_at,
                        'tone' => 'out', 'driver_ids' => $outNeeds && $outDriver ? [(int) $outDriver] : [],
                        'needs_driver' => $outNeeds && ! $outDriver && $b->status !== BookingStatus::Completed,
                        'window' => $withDriver ? [$b->start_at, $b->end_at] : [$b->start_at, $b->start_at],
                    ]);
                }
                if (! $withDriver && $b->end_at->between($this->from, $this->to)) {
                    $out->push($base + [
                        'key' => 'b'.$b->id.'-back', 'kind' => 'back',
                        'title' => ($b->return_method === 'collection' ? 'Collection: ' : 'Return: ').($b->bookable?->name ?? 'Car'),
                        'sub' => $who->push($b->return_location ?: $b->pickup_location)->push($backNeeds ? ($backDriver ? 'Driver: '.$this->name($backDriver) : 'No driver yet') : null)->filter()->implode(' · '),
                        'start' => $b->end_at, 'end' => $b->end_at,
                        'tone' => $b->status === BookingStatus::InProgress && $b->end_at->isPast() ? 'late' : 'back',
                        'driver_ids' => $backNeeds && $backDriver ? [(int) $backDriver] : [],
                        'needs_driver' => $backNeeds && ! $backDriver && $b->status !== BookingStatus::Completed,
                        'window' => [$b->end_at, $b->end_at],
                    ]);
                }

                continue;
            }

            $isTransfer = $b->service_type?->value === 'transfer';
            $out->push($base + [
                'key' => 'b'.$b->id, 'kind' => $isTransfer ? 'transfer' : 'charter',
                'title' => ($isTransfer ? 'Transfer: ' : 'Charter: ').($b->bookable?->name ?? $b->service_type?->getLabel()),
                'sub' => $who->push($b->guests ? $b->guests.' guests' : null)->push($b->pickup_location)->filter()->implode(' · '),
                'start' => $b->start_at, 'end' => $b->end_at, 'tone' => $isTransfer ? 'transfer' : 'charter',
                'driver_ids' => $b->driver_id ? [(int) $b->driver_id] : [],
                'needs_driver' => $isTransfer && ! $b->driver_id && $b->status !== BookingStatus::Completed,
                'window' => [$b->start_at, $b->end_at],
            ]);
        }

        return $out;
    }

    /** Captain and crew of charters in the window. */
    protected function jobs(): Collection
    {
        return BookingJob::query()->where('type', 'charter')->where('status', '!=', 'cancelled')
            ->where('scheduled_at', '<=', $this->to)->where(fn ($q) => $q->where('ends_at', '>=', $this->from)->orWhereNull('ends_at'))
            ->get(['booking_id', 'driver_id']);
    }

    protected function departures(): Collection
    {
        $out = collect();
        $trips = TripDate::query()->with('trip')->whereBetween('date', [$this->from->copy()->startOfDay(), $this->to])->where('is_available', true)->get();
        foreach ($trips as $d) {
            if (! $d->trip) {
                continue;
            }
            $start = $d->date->copy()->setTimeFromTimeString((string) ($d->start_time ?? $d->trip->start_time ?? '00:00'));
            $seats = $d->available_slots ?? $d->trip->guest_capacity;
            $booked = $d->bookedGuests();
            $out->push([
                'key' => 'td'.$d->id, 'kind' => 'departure', 'title' => 'Trip: '.$d->trip->name,
                'sub' => $booked.($seats ? ' / '.$seats : '').' guests booked',
                'start' => $start, 'end' => $start->copy()->addMinutes($d->trip->duration_minutes ?: 120), 'tone' => 'departure',
                'status' => null, 'statusLabel' => null, 'agent_id' => null, 'driver_ids' => [], 'item' => 'trip:'.$d->trip_id, 'service' => 'trip',
                'url' => \App\Filament\Resources\TripResource::getUrl('edit', ['record' => $d->trip_id]), 'needs_driver' => false, 'conflict' => false, 'window' => null,
            ]);
        }
        $packages = PackageDeparture::query()->with('package')->whereBetween('start_date', [$this->from->copy()->startOfDay(), $this->to])->get();
        foreach ($packages as $d) {
            if (! $d->package) {
                continue;
            }
            $out->push([
                'key' => 'pd'.$d->id, 'kind' => 'departure', 'title' => 'Package: '.$d->package->name,
                'sub' => $d->bookedGuests().' guests booked',
                'start' => $d->startsAt(), 'end' => $d->endsAt(), 'tone' => 'departure',
                'status' => null, 'statusLabel' => null, 'agent_id' => null, 'driver_ids' => [], 'item' => 'package:'.$d->package_id, 'service' => 'package',
                'url' => \App\Filament\Resources\PackageResource::getUrl('edit', ['record' => $d->package_id]), 'needs_driver' => false, 'conflict' => false, 'window' => null,
            ]);
        }

        return $out;
    }

    protected function followUps(): Collection
    {
        $q = Lead::query()->open()->whereNotNull('next_follow_up_at')->whereBetween('next_follow_up_at', [$this->from, $this->to]);
        $q = Access::agentScoped() ? $q->where('assigned_to', $this->user?->id) : $q;

        return $q->with('assignee')->get()->map(fn (Lead $l) => [
            'key' => 'l'.$l->id, 'kind' => 'followup', 'title' => 'Follow up: '.$l->name,
            'sub' => collect([$l->service_type?->getLabel(), $l->assignee?->name])->filter()->implode(' · '),
            'start' => $l->next_follow_up_at, 'end' => $l->next_follow_up_at, 'tone' => $l->next_follow_up_at->isPast() ? 'late' : 'task',
            'status' => null, 'statusLabel' => null, 'agent_id' => $l->assigned_to, 'driver_ids' => [], 'item' => null, 'service' => $l->service_type?->value,
            'url' => LeadResource::getUrl('edit', ['record' => $l]), 'needs_driver' => false, 'conflict' => false, 'window' => null,
        ]);
    }

    protected function quotes(): Collection
    {
        $q = Access::scopeOwn(Quotation::query(), 'quotation')->with(['customer', 'lead', 'bookable'])
            ->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation])
            ->whereBetween('valid_until', [$this->from->copy()->startOfDay(), $this->to]);

        return $q->get()->map(fn (Quotation $x) => [
            'key' => 'q'.$x->id, 'kind' => 'quote', 'title' => 'Quote expires: '.$x->title(),
            'sub' => $x->customerName() ?? '', 'start' => $x->valid_until->copy()->setTime(9, 0), 'end' => $x->valid_until->copy()->setTime(9, 0), 'tone' => 'task',
            'status' => null, 'statusLabel' => null, 'agent_id' => $x->lead?->assigned_to ?? $x->created_by, 'driver_ids' => [], 'item' => $x->bookable_type ? $x->bookable_type.':'.$x->bookable_id : null,
            'service' => $x->service_type?->value, 'url' => QuotationResource::getUrl('edit', ['record' => $x]), 'needs_driver' => false, 'conflict' => false, 'window' => null,
        ]);
    }

    /** Same driver / captain twice at the same time (with the gap from Business settings). */
    protected function markConflicts(Collection $events): Collection
    {
        // Captain / crew are part of their charter event.
        $crew = $this->jobs()->groupBy('booking_id');
        $events = $events->map(function ($e) use ($crew) {
            if ($e['kind'] === 'charter' && ($id = (int) substr($e['key'], 1)) && $crew->has($id)) {
                $e['driver_ids'] = array_values(array_unique([...$e['driver_ids'], ...$crew[$id]->pluck('driver_id')->filter()->map(fn ($v) => (int) $v)->all()]));
            }

            return $e;
        });

        $gap = max(0, (int) Setting::read('driver_job_gap_minutes', 90));
        $byPerson = [];
        foreach ($events as $i => $e) {
            foreach ($e['driver_ids'] as $p) {
                if ($e['window']) {
                    $byPerson[$p][] = $i;
                }
            }
        }
        $flags = [];
        foreach ($byPerson as $list) {
            for ($a = 0; $a < count($list); $a++) {
                for ($b = $a + 1; $b < count($list); $b++) {
                    [$x, $y] = [$events[$list[$a]], $events[$list[$b]]];
                    if (substr($x['key'], 0, strpos($x['key'].'-', '-')) === substr($y['key'], 0, strpos($y['key'].'-', '-'))) {
                        continue; // the same booking (handover and return)
                    }
                    if (self::clash($x['window'], $y['window'], $gap)) {
                        $flags[$list[$a]] = $flags[$list[$b]] = true;
                    }
                }
            }
        }

        return $events->map(fn ($e, $i) => isset($flags[$i]) ? ['conflict' => true] + $e : $e);
    }

    public static function clash(array $a, array $b, int $gap): bool
    {
        $aSpan = $a[0]->ne($a[1]);
        $bSpan = $b[0]->ne($b[1]);
        if ($aSpan && $bSpan) {
            return $a[0]->lt($b[1]) && $b[0]->lt($a[1]);
        }

        return $a[0]->copy()->subMinutes($gap)->lt($b[1]) && $b[0]->lt($a[1]->copy()->addMinutes($gap));
    }

    protected array $names = [];

    protected function name(int $id): string
    {
        return $this->names[$id] ??= (string) User::whereKey($id)->value('name');
    }
}
