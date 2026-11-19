<?php

namespace App\Console\Commands;

use App\Models\Car;
use Illuminate\Console\Command;

/**
 * 19 Nov (suggestion 1): one daily message to the fleet managers listing cars whose registration,
 * insurance or service (date or km) is due soon or overdue. The same list is on the dashboard.
 */
class FleetReminders extends Command
{
    protected $signature = 'crm:fleet-reminders';

    protected $description = 'Tell the fleet managers about car registration, insurance and service due soon or overdue.';

    public function handle(): int
    {
        $lines = Car::query()->documentsDueSoon()->orderBy('name')->get()
            ->map(fn (Car $c) => ($alerts = $c->documentAlerts()) ? $c->name.($c->registration_number ? ' ('.$c->registration_number.')' : '').': '.implode(', ', $alerts) : null)
            ->filter()->values();
        if ($lines->isNotEmpty()) {
            \App\Support\Drivers\Notify::staff('manage-fleet', $lines->count().' car(s) need papers or service', $lines->take(12)->implode("\n").($lines->count() > 12 ? "\n…" : ''),
                \App\Filament\Resources\CarResource::getUrl('index'), null, 'warning', 'fleet_reminder', 'fleet-reminder:'.today()->toDateString());
        }
        $this->info($lines->count().' car(s) listed');

        return self::SUCCESS;
    }
}
