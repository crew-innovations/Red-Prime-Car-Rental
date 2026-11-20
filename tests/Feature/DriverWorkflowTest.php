<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Filament\Pages\DriverJob;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Resources\BookingResource\BookingActions;
use App\Filament\Resources\DriverResource;
use App\Filament\Resources\DriverResource\RelationManagers\DocumentsRelationManager;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\DamageReport;
use App\Models\DriverDocument;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Availability;
use App\Support\Drivers\DriverPapers;
use App\Support\Drivers\DriverWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 26 Oct brief: driver profiles and documents, assignment, jobs, mobile handover / collection, damage. */
class DriverWorkflowTest extends TestCase
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
        return $this->user('Driver', ['name' => $name, 'phone' => '+971500000'.random_int(100, 999)]);
    }

    protected function selfDrive(User $driver, string $start = '2026-11-05 10:00', string $end = '2026-11-08 10:00', array $attrs = []): Booking
    {
        return $this->booking($this->car(), $start, $end, $attrs + [
            'driver_id' => $driver->id,
            'pickup_location' => 'Marina Gate 2, Dubai Marina',
            'driver_instructions' => 'Call on arrival',
        ]);
    }

    /** A real photo file on the private disk, like a finished upload. */
    protected function photo(string $name = 'p.jpg'): string
    {
        $path = 'tmp/'.uniqid().'-'.$name;
        Storage::disk('private')->put($path, UploadedFile::fake()->image($name, 40, 30)->getContent());

        return $path;
    }

    /* ---------- profile and documents ---------- */

    public function test_admin_creates_a_documented_driver_and_required_fields_follow_settings(): void
    {
        Setting::write(['driver_required_fields' => ['legal_name', 'nationality']]);

        Livewire::test(DriverResource\Pages\CreateDriver::class)
            ->fillForm(['name' => 'Imran', 'phone' => '0501112222', 'email' => 'imran@test.local'])
            ->call('create')
            ->assertHasFormErrors(['driverProfile.legal_name' => 'required', 'driverProfile.nationality' => 'required']);

        Livewire::test(DriverResource\Pages\CreateDriver::class)
            ->fillForm([
                'name' => 'Imran', 'phone' => '0501112222', 'email' => 'imran@test.local', 'is_active' => true, 'login_enabled' => true,
                'driverProfile' => ['legal_name' => 'Muhammad Imran Khan', 'nationality' => 'Pakistan', 'employee_ref' => 'DR-007', 'emergency_name' => 'Ali', 'emergency_phone' => '0509998888'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $driver = User::firstWhere('email', 'imran@test.local');
        $this->assertTrue($driver->hasRole('Driver'));
        $this->assertSame('Muhammad Imran Khan', $driver->driverProfile->legal_name);
        $this->assertSame('DR-007', $driver->driverProfile->employee_ref);
        $this->assertNotEmpty($driver->password, 'an unknown random password, the driver sets their own');
    }

    public function test_documents_are_encrypted_replaced_and_flagged_before_expiry(): void
    {
        $driver = $this->driver();
        Setting::write(['driver_required_documents' => ['passport', 'licence'], 'driver_doc_warn_days' => 30]);

        $this->assertSame(['Passport missing', 'Driving licence missing'], DriverPapers::problems($driver));

        $old = $driver->driverDocuments()->create(['type' => 'passport', 'number' => 'AB1234567', 'expires_on' => '2026-11-20']);
        $this->assertNotSame('AB1234567', DB::table('driver_documents')->where('id', $old->id)->value('number'), 'stored encrypted');
        $this->assertSame('AB1234567', $old->fresh()->number);
        $this->assertSame('•••• 4567', $old->maskedNumber());
        $this->assertSame('expiring', $old->expiryState());

        $driver->driverDocuments()->create(['type' => 'licence', 'number' => 'L-55', 'expires_on' => '2026-10-01']);
        $this->assertSame(['Driving licence expired'], DriverPapers::problems($driver->fresh()));

        $new = $driver->driverDocuments()->create(['type' => 'passport', 'number' => 'ZX7654321', 'expires_on' => '2030-01-01']);
        $this->assertNotNull($old->fresh()->replaced_at, 'the old copy is kept as history');
        $this->assertNull($new->fresh()->replaced_at);
        $this->assertSame(1, $driver->driverDocuments()->current()->where('type', 'passport')->count());
        $this->assertSame(2, $driver->driverDocuments()->where('type', 'passport')->count());
    }

    public function test_documents_tab_adds_a_file_and_only_driver_managers_see_it(): void
    {
        $driver = $this->driver();

        Livewire::test(DriverResource\RelationManagers\DocumentsRelationManager::class, ['ownerRecord' => $driver, 'pageClass' => DriverResource\Pages\EditDriver::class])
            ->callTableAction('create', data: [
                'type' => 'licence', 'number' => 'DXB-998877', 'issued_by' => 'RTA Dubai', 'classes' => ['Light vehicle'],
                'issued_on' => '2024-01-10', 'expires_on' => '2029-01-10',
                'path' => UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf'),
            ])
            ->assertHasNoTableActionErrors();

        $doc = $driver->driverDocuments()->first();
        $this->assertSame('licence', $doc->type);
        $this->assertSame(['Light vehicle'], $doc->classes);
        $this->assertTrue(Storage::disk('private')->exists($doc->path));
        $this->assertSame($this->office->id, $doc->uploaded_by);

        $this->assertTrue(DriverResource\RelationManagers\DocumentsRelationManager::canViewForRecord($driver, DriverResource\Pages\EditDriver::class));
        $this->actingAs($this->user('Sales'));
        $this->assertFalse(DriverResource\RelationManagers\DocumentsRelationManager::canViewForRecord($driver, DriverResource\Pages\EditDriver::class));
        $this->get('/crm/drivers/'.$driver->id.'/edit')->assertForbidden();
    }

    public function test_expiry_reminders_go_out_once(): void
    {
        $driver = $this->driver();
        $driver->driverDocuments()->create(['type' => 'licence', 'expires_on' => '2026-11-15']);
        auth()->logout(); // the daily task runs without a signed-in user

        $this->artisan('crm:driver-document-reminders')->assertSuccessful();
        $this->assertSame(1, $driver->notifications()->count());
        $this->assertSame(1, $this->office->notifications()->count(), 'managers manage drivers by default');

        $this->artisan('crm:driver-document-reminders')->assertSuccessful();
        $this->assertSame(1, $driver->notifications()->count(), 'not repeated');
    }

    /* ---------- assignment and availability ---------- */

    public function test_jobs_follow_the_booking_and_the_driver_is_told(): void
    {
        $driver = $this->driver();
        $booking = $this->selfDrive($driver);

        $jobs = $booking->jobs()->get()->keyBy('type');
        $this->assertSame(['collection', 'delivery'], $jobs->keys()->sort()->values()->all());
        $this->assertSame('2026-11-05 10:00', $jobs['delivery']->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('Marina Gate 2, Dubai Marina', $jobs['delivery']->address);
        $this->assertSame($this->office->id, $jobs['delivery']->assigned_by, 'who assigned it is kept');
        $this->assertSame(2, $driver->notifications()->count());

        $booking->update(['start_at' => '2026-11-05 12:00']);
        $this->assertSame('12:00', $jobs['delivery']->fresh()->scheduled_at->format('H:i'));
        $this->assertSame(3, $driver->notifications()->count(), 'told about the change');

        $other = $this->driver('Aaron');
        $booking->update(['driver_id' => $other->id]);
        $this->assertSame($other->id, $jobs['delivery']->fresh()->driver_id);
        $this->assertSame('assigned', $jobs['delivery']->fresh()->status);
        $this->assertSame(2, $other->notifications()->count());

        $booking->update(['status' => BookingStatus::Cancelled, 'cancel_reason' => 'Changed plans']);
        $this->assertSame(['cancelled'], $booking->jobs()->pluck('status')->unique()->values()->all());
    }

    public function test_driver_clashes_use_delivery_and_collection_times_and_are_checked_on_save(): void
    {
        Setting::write(['driver_job_gap_minutes' => 90]);
        $driver = $this->driver();
        $this->selfDrive($driver, '2026-11-05 10:00', '2026-11-08 10:00');

        // Self-drive: the driver is only busy at the delivery and the collection.
        $this->assertNull(Availability::driverConflict($driver->id, '2026-11-06 10:00', '2026-11-06 18:00', null, DriveMode::SelfDrive));
        $this->assertNotNull(Availability::driverConflict($driver->id, '2026-11-05 11:00', '2026-11-06 11:00', null, DriveMode::SelfDrive), 'less than 90 minutes after a delivery');
        $this->assertNotNull(Availability::driverConflict($driver->id, '2026-11-05 09:00', '2026-11-05 12:00', null, DriveMode::WithDriver), 'chauffeur time covers the delivery');

        $this->booking($this->car(), '2026-11-06 10:00', '2026-11-06 18:00', ['driver_id' => $driver->id]);

        $this->expectException(ValidationException::class);
        $this->booking($this->car(), '2026-11-08 09:00', '2026-11-08 20:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $driver->id]);
    }

    public function test_inactive_drivers_get_no_new_jobs_and_options_show_who_is_busy(): void
    {
        $busy = $this->driver('Busy');
        $free = $this->driver('Free');
        $this->selfDrive($busy, '2026-11-05 10:00', '2026-11-08 10:00');

        $options = \App\Filament\Resources\BookingResource\BookingActions::driverOptions(null, '2026-11-05 10:30', '2026-11-05 20:00', 'self_drive');
        $this->assertStringContainsString(\App\Filament\Resources\BookingResource\BookingActions::BUSY, $options[$busy->id]);
        $this->assertStringNotContainsString(\App\Filament\Resources\BookingResource\BookingActions::BUSY, $options[$free->id]);

        $off = $this->user('Driver', ['name' => 'Off', 'is_active' => false]);
        try {
            $this->booking($this->car(), '2026-11-10 10:00', '2026-11-11 10:00', ['driver_id' => $off->id]);
            $this->fail('inactive driver was assigned');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('inactive', collect($e->errors())->flatten()->implode(' '));
        }

        // 20 Nov (Bilal, item 8): incomplete documents never block a job; a warning is given instead.
        Setting::write(['driver_block_incomplete' => true, 'driver_required_documents' => ['licence']]);
        \App\Models\Booking::$driverNotice = null;
        $b = $this->booking($this->car(), '2026-11-12 10:00', '2026-11-13 10:00', ['driver_id' => $free->id]);
        $this->assertSame($free->id, $b->driver_id);
        $this->assertStringContainsString('Driving licence missing', (string) \App\Models\Booking::$driverNotice);
        $this->assertStringContainsString('documents incomplete', BookingActions::driverOptions()[$free->id]);
        \App\Models\Booking::$driverNotice = null;
    }

    /* ---------- the driver's screens ---------- */

    public function test_driver_sees_only_own_jobs_and_cannot_open_others(): void
    {
        $me = $this->driver();
        $other = $this->driver('Aaron');
        $mine = $this->selfDrive($me);
        $theirs = $this->selfDrive($other, '2026-11-05 15:00', '2026-11-06 15:00');

        $this->actingAs($me);
        $this->get('/crm/my-jobs')->assertOk()->assertSee($mine->reference)->assertDontSee($theirs->reference);
        $this->get(DriverJob::getUrl(['job' => $mine->jobs()->where('type', 'delivery')->first()->id]))->assertOk()->assertSee('Marina Gate 2')->assertSee('Call on arrival')
            ->assertSee('Hand over the car')->assertDontSee('Collect the car');
        $this->get(DriverJob::getUrl(['job' => $theirs->jobs()->first()->id]))->assertNotFound();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        DriverWork::handOver($theirs->jobs()->where('type', 'delivery')->first(), $me, ['km' => 10000, 'fuel' => 'full', 'photos' => [$this->photo()]]);
    }

    public function test_customer_phone_and_instructions_follow_settings(): void
    {
        $me = $this->driver();
        $booking = $this->selfDrive($me);
        $url = DriverJob::getUrl(['job' => $booking->jobs()->first()->id]);
        $this->actingAs($me);

        $this->get($url)->assertSee('tel:', false);
        Setting::write(['driver_sees_customer_phone' => false, 'driver_sees_instructions' => false]);
        $this->get($url)->assertDontSee('tel:', false)->assertDontSee('Call on arrival');
    }

    public function test_handover_needs_the_checks_records_no_money_and_completes_only_when_saved(): void
    {
        Setting::write(['handover_min_photos' => 2]);
        $me = $this->driver();
        $booking = $this->selfDrive($me);
        $job = $booking->jobs()->where('type', 'delivery')->first();
        $money = Transaction::where('is_balance', false)->count();
        $this->actingAs($me);

        try {
            DriverWork::handOver($job, $me, ['km' => 10000, 'fuel' => 'full', 'photos' => [$this->photo()]]);
            $this->fail('handover saved with too few photos');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.photos', $e->errors());
        }
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status, 'nothing marked done after a failed save');
        $this->assertTrue($job->fresh()->isOpen());

        Livewire::test(DriverJob::class, ['job' => $job->id])
            ->callAction('handover', data: [
                'km' => 10050, 'fuel' => 'full', 'checklist' => ['Keys handed over'],
                'photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')],
                'notes' => 'Scratch on rear bumper shown to customer',
            ])
            ->assertHasNoActionErrors();

        $booking->refresh();
        $this->assertSame(BookingStatus::InProgress, $booking->status);
        $this->assertSame(10050, (int) $booking->delivery_km);
        $this->assertSame($me->id, $booking->delivered_by);
        $this->assertSame(2, $booking->getMedia('delivery')->count());
        $this->assertStringContainsString('Scratch on rear bumper', $booking->delivery_notes);
        $this->assertSame($money, Transaction::where('is_balance', false)->count(), 'drivers never record money');
        $this->assertSame('done', $job->fresh()->status);
        $this->assertSame(10050, $job->fresh()->inspection['km']);
        $this->assertGreaterThan(0, $this->office->notifications()->count(), 'the office hears about it');
    }

    public function test_collection_reports_damage_and_the_office_completes_the_return(): void
    {
        Setting::write(['return_min_photos' => 1]);
        $me = $this->driver();
        $booking = $this->selfDrive($me);
        $this->actingAs($me);
        DriverWork::handOver($booking->jobs()->where('type', 'delivery')->first(), $me, ['km' => 10000, 'fuel' => 'full', 'photos' => [$this->photo()]]);
        $job = $booking->jobs()->where('type', 'collection')->first();

        try {
            DriverWork::collect($job, $me, ['km' => 10400, 'fuel' => 'half', 'condition' => 'damaged', 'photos' => [$this->photo()]]);
            $this->fail('damage without a description');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.damage', $e->errors());
        }

        Carbon::setTestNow('2026-11-08 10:30:00');
        DriverWork::collect($job->fresh(), $me, ['km' => 10400, 'fuel' => 'half', 'condition' => 'damaged', 'damage' => 'Dent on the driver door', 'photos' => [$this->photo(), $this->photo()]]);

        $booking->refresh();
        $this->assertSame(BookingStatus::InProgress, $booking->status, 'the office closes the return (charges, payment, deposit)');
        $this->assertSame('done', $job->fresh()->status);
        $this->assertSame('Collected, office to close', $job->fresh()->statusLabel());
        $this->assertSame(2, $booking->getMedia('return')->count());
        $report = DamageReport::firstWhere('booking_id', $booking->id);
        $this->assertSame('open', $report->status);
        $this->assertSame($me->id, $report->reported_by);
        $this->assertSame(0.0, (float) $booking->extra_charges, 'no charge without the office');

        // Office: the return form starts from the driver's check.
        $this->actingAs($this->office);
        Livewire::test(EditBooking::class, ['record' => $booking->id])
            ->mountAction('receive')
            ->assertActionDataSet(['return_km' => 10400, 'return_fuel' => 'half', 'return_condition' => 'damaged', 'after_return' => 'maintenance']);
    }

    public function test_driver_steps_follow_settings(): void
    {
        $me = $this->driver();
        $job = $this->selfDrive($me)->jobs()->where('type', 'delivery')->first();
        $this->actingAs($me);

        Livewire::test(DriverJob::class, ['job' => $job->id])->call('setStep', 'on_the_way');
        $this->assertSame('on_the_way', $job->fresh()->status);
        $this->assertNotNull($job->fresh()->acknowledged_at);

        Setting::write(['driver_steps' => ['acknowledged']]);
        Livewire::test(DriverJob::class, ['job' => $job->id])->call('setStep', 'arrived')->assertForbidden();
        $this->assertSame('on_the_way', $job->fresh()->status);
    }

    public function test_driver_reports_damage_and_staff_review_it(): void
    {
        $me = $this->driver();
        $job = $this->selfDrive($me)->jobs()->where('type', 'delivery')->first();
        $this->actingAs($me);
        DriverWork::reportDamage($job, $me, 'Windscreen chip before handover', [$this->photo()]);

        $report = DamageReport::first();
        $this->assertSame(1, $report->getMedia('photos')->count());

        $this->actingAs($this->user('Operations'));
        Livewire::test(\App\Filament\Resources\DamageReportResource\Pages\ListDamageReports::class)
            ->assertCanSeeTableRecords([$report])
            ->callTableAction('review', $report, data: ['status' => 'no_charge', 'review_note' => 'Existing chip'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('no_charge', $report->fresh()->status);

        $this->actingAs($me);
        $this->get('/crm/damage-reports')->assertForbidden();
    }

    public function test_switching_off_driver_logins_keeps_jobs(): void
    {
        $me = $this->driver();
        $booking = $this->selfDrive($me);

        $this->actingAs($me);
        $this->get('/crm/my-jobs')->assertOk();

        $me->update(['login_enabled' => false]);
        $this->get('/crm/my-jobs')->assertForbidden();

        $me->update(['login_enabled' => true]);
        Setting::write(['driver_login_enabled' => false]);
        $this->get('/crm/my-jobs')->assertForbidden();
        $this->assertSame(2, $booking->jobs()->open()->count(), 'jobs stay on the booking');
        $this->assertSame($me->id, $booking->fresh()->driver_id);
    }
    /* ---------- 20 Nov (item 8): drivers upload documents, the office approves or rejects ---------- */

    public function test_driver_uploads_a_document_in_the_portal_and_the_office_approves_or_rejects_it(): void
    {
        Storage::fake('private');
        Setting::write(['driver_required_documents' => ['licence', 'passport']]);
        $driver = $this->driver();
        $driver->driverDocuments()->create(['type' => 'licence', 'number' => 'OLD-1', 'expires_on' => '2027-01-01', 'uploaded_by' => $this->office->id]); // office copy: approved
        $this->assertSame('approved', $driver->driverDocuments()->first()->status);

        $this->actingAs($driver);
        $this->get(\App\Filament\Pages\MyProfile::getUrl())->assertOk()->assertSee('Send a document')->assertSee('Missing'); // passport missing
        Livewire::test(\App\Filament\Pages\MyProfile::class)
            ->fillForm(['type' => 'licence', 'number' => 'NEW-2', 'expires_on' => '2030-01-01', 'path' => \Illuminate\Http\UploadedFile::fake()->image('licence.jpg')], 'docForm')
            ->call('saveDocument')->assertHasNoFormErrors([], 'docForm')->assertNotified();
        Livewire::test(\App\Filament\Pages\MyProfile::class)
            ->fillForm(['type' => 'passport', 'path' => \Illuminate\Http\UploadedFile::fake()->create('passport.pdf', 50, 'application/pdf')], 'docForm')
            ->call('saveDocument');

        $new = DriverDocument::where('user_id', $driver->id)->where('type', 'licence')->where('status', 'pending')->sole();
        $this->assertSame('NEW-2', $new->number);
        $this->assertSame('pending', $new->status);
        $this->assertNull(DriverDocument::where('user_id', $driver->id)->where('type', 'licence')->where('status', 'approved')->sole()->replaced_at, 'the approved copy stays valid while the new one waits');
        $check = DriverPapers::checklist($driver);
        $this->assertSame('approved', $check['licence']['state']);
        $this->assertSame('pending', $check['passport']['state']);
        $this->assertContains('Passport waiting for approval', DriverPapers::problems($driver));
        $this->assertTrue(\Illuminate\Notifications\DatabaseNotification::where('notifiable_id', $this->office->id)->exists(), 'the office is told');

        // The office approves the licence and rejects the passport.
        $this->flushSession();
        $this->actingAs($this->office);
        $rm = Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $driver, 'pageClass' => \App\Filament\Resources\DriverResource\Pages\EditDriver::class]);
        $rm->assertSee('Waiting for approval')->callTableAction('approve', $new)->assertHasNoTableActionErrors();
        $this->assertSame('approved', $new->fresh()->status);
        $this->assertNotNull(DriverDocument::where('user_id', $driver->id)->where('type', 'licence')->whereKeyNot($new->id)->sole()->replaced_at, 'old copy kept as history');
        $passport = DriverDocument::where('user_id', $driver->id)->where('type', 'passport')->sole();
        $rm->callTableAction('reject', $passport, ['review_note' => 'Photo is blurred'])->assertHasNoTableActionErrors();
        $this->assertSame('rejected', $passport->fresh()->status);
        $this->assertContains('Passport rejected (send it again)', DriverPapers::problems($driver));

        // The office can upload for the driver: approved at once.
        $rm->callTableAction('create', data: ['type' => 'passport', 'number' => 'P123', 'expires_on' => '2031-01-01', 'path' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg')])->assertHasNoTableActionErrors();
        $this->assertSame([], DriverPapers::problems($driver->fresh()));
    }
}
