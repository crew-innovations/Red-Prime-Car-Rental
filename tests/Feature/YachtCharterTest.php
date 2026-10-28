<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\Drivers\DriverWork;
use App\Support\Pricing\PriceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 5: private yacht charters with captain and crew from the driver module. */
class YachtCharterTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        $this->actingAs($this->user('Operations'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function person(array $types, string $name = 'Crew'): User
    {
        $u = User::create(['name' => $name.' '.\Illuminate\Support\Str::random(3), 'email' => \Illuminate\Support\Str::random(8).'@crew.test', 'password' => 'x'.\Illuminate\Support\Str::random(16), 'is_active' => true]);
        $u->assignRole('Driver');
        DriverProfile::create(['user_id' => $u->id, 'work_types' => $types]);

        return $u;
    }

    protected function charter($yacht, string $start, string $end, array $attrs = []): Booking
    {
        return $this->booking($yacht, $start, $end, $attrs + ['guests' => 8]);
    }

    public function test_hourly_and_daily_charters_are_priced_by_the_engine(): void
    {
        $yacht = $this->yacht();
        $three = PriceEngine::calculate(['service_type' => 'yacht', 'item' => $yacht, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-10 13:00', 'guests' => 6]);
        $this->assertSame(['hour', 3, 2700.0], [$three['rate_type'], $three['quantity'], $three['base_amount']]);
        $this->assertSame(3000.0, $three['deposit']);

        $day = PriceEngine::calculate(['service_type' => 'yacht', 'item' => $yacht, 'start_at' => '2026-11-10 09:00', 'end_at' => '2026-11-10 19:00']);
        $this->assertSame(['full_day', 7000.0], [$day['rate_type'], $day['base_amount']]);

        $b = $this->charter($yacht, '2026-11-10 10:00', '2026-11-10 13:00');
        $this->assertSame('2700.00', $b->total);
        $this->assertSame('with_crew', $b->crew_mode);
    }

    public function test_guest_limit_and_bareboat_rules(): void
    {
        $yacht = $this->yacht(['max_guests' => 10]);
        try {
            $this->charter($yacht, '2026-11-10 10:00', '2026-11-10 13:00', ['guests' => 14]);
            $this->fail('too many guests');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.guests', $e->errors());
        }

        try {
            $this->charter($yacht, '2026-11-11 10:00', '2026-11-11 13:00', ['crew_mode' => 'bareboat']);
            $this->fail('bareboat on a yacht that does not allow it');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.crew_mode', $e->errors());
        }

        $yacht->update(['bareboat_allowed' => true]);
        $this->settings(['doc_requirements' => ['yacht' => ['emirates_id'], 'bareboat' => ['other']]]);
        $b = $this->charter($yacht->fresh(), '2026-11-11 10:00', '2026-11-11 13:00', ['crew_mode' => 'bareboat', 'captain_id' => $this->person(['captain'])->id]);
        $this->assertNull($b->captain_id, 'no crew on a bareboat charter');
        $this->assertEqualsCanonicalizing(['emirates_id', 'other'], $b->requiredDocumentTypes());
        $this->assertSame(0, $b->jobs()->count());
    }

    public function test_captain_and_crew_get_charter_jobs_and_must_be_set_up_for_it(): void
    {
        $yacht = $this->yacht();
        $captain = $this->person(['captain'], 'Captain');
        $deck = $this->person(['crew'], 'Deckhand');
        $driver = $this->person(['driver'], 'Driver');

        try {
            $this->charter($yacht, '2026-11-10 10:00', '2026-11-10 14:00', ['captain_id' => $driver->id]);
            $this->fail('a car driver became captain');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.captain_id', $e->errors());
        }

        $b = $this->charter($yacht, '2026-11-10 10:00', '2026-11-10 14:00', ['captain_id' => $captain->id, 'crew_ids' => [$deck->id], 'pickup_location' => 'Dubai Marina, pier 7']);
        $jobs = $b->jobs()->get()->keyBy('driver_id');
        $this->assertSame('captain', $jobs[$captain->id]->role);
        $this->assertSame('crew', $jobs[$deck->id]->role);
        $this->assertSame('Dubai Marina, pier 7', $jobs[$captain->id]->address);
        $this->assertTrue($jobs[$captain->id]->ends_at->equalTo(Carbon::parse('2026-11-10 14:00')));
        $this->assertSame(1, $captain->notifications()->count());

        // Crew member taken off: his job is cancelled and he is told.
        $b->update(['crew_ids' => []]);
        $this->assertSame('cancelled', $jobs[$deck->id]->fresh()->status);

        // Charter cancelled: the captain's job is cancelled too.
        $b->update(['status' => BookingStatus::Cancelled, 'cancel_reason' => 'Weather']);
        $this->assertSame('cancelled', $jobs[$captain->id]->fresh()->status);
    }

    public function test_one_person_cannot_be_on_two_jobs_at_once(): void
    {
        $captain = $this->person(['captain', 'driver'], 'Captain');
        $this->charter($this->yacht(), '2026-11-10 10:00', '2026-11-10 14:00', ['captain_id' => $captain->id]);

        try {
            $this->charter($this->yacht(), '2026-11-10 12:00', '2026-11-10 16:00', ['captain_id' => $captain->id]);
            $this->fail('captain on two charters');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.captain_id', $e->errors());
        }

        // The same person as a with-driver car driver during the charter is refused too.
        try {
            $this->booking($this->car(), '2026-11-10 11:00', '2026-11-10 13:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $captain->id]);
            $this->fail('driver busy on a charter');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.driver_id', $e->errors());
        }
    }

    public function test_crew_only_people_are_not_offered_as_car_drivers(): void
    {
        $deck = $this->person(['crew'], 'Deckhand');
        try {
            $this->booking($this->car(), '2026-11-10 11:00', '2026-11-10 13:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $deck->id]);
            $this->fail('crew-only person used as driver');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.driver_id', $e->errors());
        }
        $this->assertArrayNotHasKey($deck->id, \App\Filament\Resources\BookingResource\BookingActions::driverOptions());
        $this->assertArrayHasKey($deck->id, \App\Filament\Resources\BookingResource\BookingActions::crewOptions('crew'));
    }

    public function test_captain_starts_and_finishes_the_charter_on_the_phone(): void
    {
        $captain = $this->person(['captain'], 'Captain');
        $deck = $this->person(['crew'], 'Deckhand');
        $b = $this->charter($this->yacht(), '2026-11-02 10:00', '2026-11-02 14:00', ['captain_id' => $captain->id, 'crew_ids' => [$deck->id]]);
        $captainJob = BookingJob::where('driver_id', $captain->id)->first();
        $deckJob = BookingJob::where('driver_id', $deck->id)->first();

        $this->assertFalse(DriverWork::canStartCharter($deckJob), 'crew cannot start it');
        try {
            DriverWork::startCharter($captainJob, $deck, []);
            $this->fail('someone else started the captain job');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);
        }

        $this->actingAs($captain);
        DriverWork::startCharter($captainJob->fresh(), $captain, ['guests' => 8, 'notes' => 'All aboard']);
        $b->refresh();
        $this->assertSame(BookingStatus::InProgress, $b->status);
        $this->assertNotNull($b->delivered_at);
        $this->assertSame(8, $captainJob->fresh()->inspection['guests']);
        $this->assertSame('0.00', $b->paid_amount, 'the captain records no money');

        DriverWork::finishCharter($captainJob->fresh(), $captain, ['notes' => '30 minutes extra']);
        $this->assertSame('done', $captainJob->fresh()->status);
        $this->assertSame('done', $deckJob->fresh()->status);
        $this->assertSame(BookingStatus::InProgress, $b->fresh()->status, 'the office closes it');

        $this->actingAs($captain);
        $this->get(\App\Filament\Pages\DriverJob::getUrl(['job' => $captainJob->id]))->assertOk()->assertSee('Marina');
    }

    public function test_charter_quotation_keeps_the_crew_choice_into_the_booking(): void
    {
        $this->actingAs($this->user('Sales'));
        $yacht = $this->yacht(['bareboat_allowed' => true]);
        $q = \App\Models\Quotation::create(['customer_id' => $this->customer()->id, 'service_type' => ServiceType::Yacht, 'bookable_id' => $yacht->id,
            'crew_mode' => 'bareboat', 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-10 13:00', 'guests' => 6, 'valid_until' => '2026-11-06']);
        $this->assertSame('bareboat', $q->crew_mode);
        $this->assertSame('2700.00', $q->total);
        $q->update(['status' => 'sent', 'sent_at' => now()]);
        \App\Support\Sales\QuotationDecision::accept($q->fresh(), 'staff', 'Guest');

        \Livewire\Livewire::test(\App\Filament\Resources\QuotationResource\Pages\EditQuotation::class, ['record' => $q->id])
            ->callAction('convert', ['status' => 'confirmed', 'method' => 'card'])->assertHasNoActionErrors();
        $this->assertSame('bareboat', Booking::first()->crew_mode);
    }
}
