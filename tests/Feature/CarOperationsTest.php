<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Models\Booking;
use App\Models\DamageReport;
use App\Models\User;
use App\Support\BookingFlow;
use App\Support\Drivers\DriverWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 4: delivery choices, collection driver, handover approval, approved damage charges. */
class CarOperationsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected User $office;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 08:00:00');
        $this->seedRoles();
        $this->office = $this->user('Manager');
        $this->actingAs($this->office);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function driver(string $name = 'Imran'): User
    {
        return $this->user('Driver', ['name' => $name]);
    }

    protected function photo(): string
    {
        $path = 'tmp/'.uniqid().'-p.jpg';
        Storage::disk('private')->put($path, UploadedFile::fake()->image('p.jpg', 40, 30)->getContent());

        return $path;
    }

    protected function selfDrive(array $attrs, string $start = '2026-11-05 10:00', string $end = '2026-11-08 10:00'): Booking
    {
        return $this->booking($this->car(), $start, $end, $attrs + ['pickup_location' => 'JBR Walk', 'adjustment_reason' => null]);
    }

    public function test_jobs_follow_the_delivery_and_return_choice(): void
    {
        $d = $this->driver();

        $office = $this->selfDrive(['driver_id' => $d->id, 'delivery_method' => 'office', 'return_method' => 'office']);
        $this->assertSame(0, $office->jobs()->count(), 'customer collects and returns: no driver needed');

        $deliverOnly = $this->selfDrive(['driver_id' => $d->id, 'delivery_method' => 'delivery', 'return_method' => 'office'], '2026-11-10 10:00', '2026-11-12 10:00');
        $this->assertSame(['delivery'], $deliverOnly->jobs()->pluck('type')->all());

        $chauffeur = $this->booking($this->car(), '2026-11-15 10:00', '2026-11-15 18:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $d->id, 'delivery_method' => 'office', 'return_method' => 'office']);
        $this->assertEqualsCanonicalizing(['delivery', 'collection'], $chauffeur->jobs()->pluck('type')->all(), 'with driver always needs both');
    }

    public function test_another_driver_can_do_the_collection(): void
    {
        $first = $this->driver('First');
        $second = $this->driver('Second');
        $b = $this->selfDrive(['driver_id' => $first->id, 'delivery_method' => 'delivery', 'return_method' => 'collection']);
        $this->assertSame($first->id, $b->jobs()->where('type', 'collection')->value('driver_id'), 'the delivery driver is offered for the return');

        $b->update(['return_driver_id' => $second->id]);
        $this->assertSame($second->id, $b->jobs()->where('type', 'collection')->value('driver_id'));
        $this->assertSame($first->id, $b->jobs()->where('type', 'delivery')->value('driver_id'));

        // The collection driver is checked at the return time only.
        $this->booking($this->car(), '2026-11-08 09:30', '2026-11-08 12:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $this->driver('Busy')->id]);
        try {
            $this->selfDrive(['driver_id' => $first->id, 'delivery_method' => 'delivery', 'return_method' => 'collection',
                'return_driver_id' => User::where('name', 'Busy')->value('id')], '2026-11-06 10:00', '2026-11-08 10:00');
            $this->fail('busy collection driver accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.return_driver_id', $e->errors());
        }
    }

    public function test_handover_waits_for_the_office_when_settings_say_so(): void
    {
        $this->settings(['handover_requires_approval' => true]);
        $d = $this->driver();
        $b = $this->selfDrive(['driver_id' => $d->id, 'delivery_method' => 'delivery', 'return_method' => 'collection']);
        $job = $b->jobs()->where('type', 'delivery')->first();

        $this->actingAs($d);
        DriverWork::handOver($job, $d, ['km' => 10100, 'fuel' => 'full', 'photos' => [$this->photo()]]);
        $this->assertSame('waiting', $job->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status, 'not released yet');
        $this->assertFalse(DriverWork::canHandOver($job->fresh()));
        $this->assertSame(1, $b->fresh()->getMedia('delivery')->count(), 'photos are kept for the review');

        // Not OK: back to the driver with a reason.
        $this->actingAs($this->office);
        DriverWork::rejectHandover($job->fresh(), $this->office, 'Photo of the front bumper please');
        $this->assertSame('arrived', $job->fresh()->status);
        $this->assertSame('Photo of the front bumper please', $job->fresh()->inspection['rejected']);

        $this->actingAs($d);
        DriverWork::handOver($job->fresh(), $d, ['km' => 10100, 'fuel' => 'full', 'photos' => [$this->photo()]]);
        $this->actingAs($this->office);
        Livewire::test(\App\Filament\Resources\BookingResource\Pages\EditBooking::class, ['record' => $b->id])->callAction('approveHandover')->assertHasNoActionErrors();

        $b->refresh();
        $this->assertSame(BookingStatus::InProgress, $b->status);
        $this->assertSame(10100, (int) $b->delivery_km);
        $this->assertSame($d->id, $b->delivered_by);
        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertSame($this->office->id, $job->approved_by);
    }

    public function test_damage_is_only_payable_after_review(): void
    {
        $d = $this->driver();
        $b = $this->selfDrive(['driver_id' => $d->id, 'delivery_method' => 'delivery', 'return_method' => 'collection']);
        BookingFlow::deliver($b, ['delivered_at' => now(), 'delivery_km' => 10000]);
        $report = DamageReport::create(['booking_id' => $b->id, 'reported_by' => $d->id, 'stage' => 'collection', 'description' => 'Scratch rear door', 'status' => 'open']);

        // Staff without "review damage" cannot charge it yet.
        $this->actingAs($this->user('Staff'));
        try {
            BookingFlow::receive($b->fresh(), ['returned_at' => now(), 'return_km' => 10300, 'charges' => ['damage' => 500]]);
            $this->fail('unapproved damage charged');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.charges.damage', $e->errors());
        }

        // Reviewer approves AED 400: it is filled in and charged once.
        $this->actingAs($this->user('Operations'));
        $report->update(['status' => 'charged', 'charge_amount' => 400, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        $this->assertSame(400.0, DamageReport::approvedUnapplied($b->id));

        $this->actingAs($this->user('Staff'));
        BookingFlow::receive($b->fresh(), ['returned_at' => now(), 'return_km' => 10300, 'charges' => ['damage' => 400]]);
        $b->refresh();
        $this->assertSame('400.00', $b->extra_charges);
        $this->assertNotNull($report->fresh()->applied_at);
        $this->assertSame(0.0, DamageReport::approvedUnapplied($b->id));

        // A charge approved after the return is added straight to the booking (and to collect).
        $late = DamageReport::create(['booking_id' => $b->id, 'reported_by' => $d->id, 'stage' => 'collection', 'description' => 'Seat stain', 'status' => 'charged', 'charge_amount' => 150]);
        $late->applyChargeIfReturned();
        $b->refresh();
        $this->assertSame('550.00', $b->extra_charges);
        $this->assertSame(550.0, (float) $b->charges['damage']);
        $this->assertGreaterThanOrEqual(550, (float) $b->balance);
    }
}
