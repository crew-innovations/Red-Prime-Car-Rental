<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Filament\Pages\Calendar;
use App\Models\Lead;
use App\Support\Schedule\CalendarFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 28 Oct-2, Phase 9: calendar views, role scope, clashes, jobs without a driver, alternatives. */
class CalendarTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00'); // Monday
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function week(array $filters = []): \Illuminate\Support\Collection
    {
        return (new CalendarFeed(Carbon::parse('2026-11-02'), Carbon::parse('2026-11-08 23:59:59'), $filters))->events();
    }

    public function test_week_shows_handovers_returns_departures_and_follow_ups(): void
    {
        $manager = $this->user('Manager');
        $this->actingAs($manager);
        $driver = $this->user('Driver', ['name' => 'Imran']);
        $car = $this->car(['name' => 'Urus']);
        $b = $this->booking($car, '2026-11-03 10:00', '2026-11-05 10:00', ['delivery_method' => 'delivery', 'return_method' => 'collection', 'driver_id' => $driver->id, 'pickup_location' => 'JBR']);
        $trip = $this->trip(null, ['name' => 'Sunset cruise', 'guest_capacity' => 10, 'start_time' => '17:00:00']);
        $this->tripDate($trip, '2026-11-06', ['available_slots' => 10]);
        Lead::create(['name' => 'Omar', 'phone' => '0501231231', 'pipeline' => 'car', 'service_type' => 'car', 'assigned_to' => $manager->id, 'next_follow_up_at' => '2026-11-04 12:00']);

        $events = $this->week();
        $kinds = $events->pluck('kind')->all();
        $this->assertSame(['out', 'followup', 'back', 'departure'], $kinds, 'sorted by time');
        $out = $events->firstWhere('kind', 'out');
        $this->assertSame('Delivery: Urus', $out['title']);
        $this->assertStringContainsString('Driver: Imran', $out['sub']);
        $this->assertSame([$driver->id], $out['driver_ids']);
        $this->assertSame('Trip: Sunset cruise', $events->firstWhere('kind', 'departure')['title']);

        // Filters: by car and by driver.
        $this->assertCount(2, $this->week(['item' => 'car:'.$car->id]));
        $this->assertCount(2, $this->week(['driver' => $driver->id]));
        $this->assertCount(0, $this->week(['status' => BookingStatus::Cancelled->value]));

        foreach (['day', 'week', 'month', 'agenda'] as $mode) {
            Livewire::test(Calendar::class)->set('date', '2026-11-03')->set('mode', $mode)->assertOk()->assertSee('Urus');
        }
    }

    public function test_clashes_and_jobs_without_a_driver_are_flagged_and_counted_on_the_dashboard(): void
    {
        $this->actingAs($this->user('Manager'));
        $driver = $this->user('Driver', ['name' => 'Imran']);
        $a = $this->booking($this->car(), '2026-11-03 10:00', '2026-11-05 10:00', ['delivery_method' => 'delivery', 'driver_id' => $driver->id]);
        $b = $this->booking($this->car(), '2026-11-03 18:00', '2026-11-04 18:00', ['drive_mode' => DriveMode::WithDriver]);
        // An older double booking saved before the checks existed.
        DB::table('bookings')->where('id', $b->id)->update(['driver_id' => $driver->id, 'start_at' => '2026-11-03 10:30:00']);
        $c = $this->booking($this->car(), '2026-11-06 10:00', '2026-11-07 10:00', ['delivery_method' => 'delivery']);

        $events = $this->week();
        $this->assertTrue($events->firstWhere('key', 'b'.$a->id.'-out')['conflict']);
        $this->assertTrue($events->firstWhere('key', 'b'.$b->id.'-out')['conflict']);
        $this->assertTrue($events->firstWhere('key', 'b'.$c->id.'-out')['needs_driver']);
        $this->assertCount(3, $this->week(['problems' => true]));

        Livewire::test(\App\Filament\Widgets\BusinessBoard::class)->assertSee('Schedule · next 7 days')->assertSee('Clashes (same person twice)');
        Livewire::test(Calendar::class)->set('date', '2026-11-02')->assertSee('jobs clash')->assertSee('needs a driver');
    }

    public function test_clash_message_offers_free_drivers(): void
    {
        $this->actingAs($this->user('Manager'));
        $imran = $this->user('Driver', ['name' => 'Imran']);
        $this->user('Driver', ['name' => 'Bilal Free']);
        $this->booking($this->car(), '2026-11-03 10:00', '2026-11-05 10:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $imran->id]);
        try {
            $this->booking($this->car(), '2026-11-04 10:00', '2026-11-04 18:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $imran->id]);
            $this->fail('double booking');
        } catch (ValidationException $e) {
            $msg = $e->errors()['data.driver_id'][0];
            $this->assertStringContainsString('Imran is already busy', $msg);
            $this->assertStringContainsString('Free at that time: Bilal Free', $msg);
        }
    }

    public function test_driver_sees_only_own_jobs_and_agent_only_own_bookings(): void
    {
        $agent = $this->user('Agent');
        $this->actingAs($agent);
        $driver = $this->user('Driver', ['name' => 'Imran']);
        $mine = $this->booking($this->car(['name' => 'Mine Car']), '2026-11-03 10:00', '2026-11-04 10:00', ['assigned_to' => $agent->id, 'delivery_method' => 'delivery', 'driver_id' => $driver->id]);
        $this->booking($this->car(['name' => 'Other Car']), '2026-11-03 12:00', '2026-11-04 12:00', ['assigned_to' => $this->user('Agent')->id]);

        $titles = $this->week()->pluck('title')->implode('|');
        $this->assertStringContainsString('Mine Car', $titles);
        $this->assertStringNotContainsString('Other Car', $titles);

        $this->actingAs($driver);
        Livewire::test(Calendar::class)->set('date', '2026-11-02')
            ->assertSee('Mine Car')->assertDontSee('Other Car')->assertDontSee('Agent:')
            ->assertSee(\App\Filament\Pages\MyJobs::getUrl(), false)
            ->assertDontSee(\App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $mine]), false);
    }
}
