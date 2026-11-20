<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\AuditLog;
use App\Models\JobReport;
use App\Support\Accounts;
use App\Support\BookingFlow;
use App\Support\Documents\JobReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 28 Oct-2, Phase 6: job completion reports. */
class JobReportsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        Storage::fake('private');
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function completedBooking(array $return = [])
    {
        $b = $this->booking($this->car(), '2026-11-02 10:00', '2026-11-04 10:00');
        $b->recordPayment((float) $b->fresh()->total, 'card');
        BookingFlow::deliver($b->fresh(), ['delivered_at' => now(), 'delivery_km' => 10000, 'delivery_fuel' => 'full']);
        BookingFlow::receive($b->fresh(), $return + ['returned_at' => now()->addDays(2), 'return_km' => 10300, 'return_fuel' => 'full', 'return_condition' => 'good']);

        return $b->fresh();
    }

    public function test_completing_a_job_makes_the_report_once(): void
    {
        $this->actingAs($this->user('Operations'));
        $b = $this->completedBooking();

        $this->assertSame(BookingStatus::Completed, $b->status);
        $reports = JobReport::where('booking_id', $b->id)->get();
        $this->assertCount(1, $reports);
        $r = $reports->first();
        $this->assertTrue($r->is_current);
        $this->assertSame(1, $r->version);
        $this->assertMatchesRegularExpression('/^JR-\d{5}$/', $r->number);
        Storage::disk('private')->assertExists($r->path);
        $this->assertSame(hash('sha256', Storage::disk('private')->get($r->path)), $r->sha256);
        $this->assertTrue(AuditLog::where('event', 'job_report')->where('auditable_id', $b->id)->exists());

        // Saving the completed booking again does not make another one.
        $b->update(['return_notes' => 'All fine']);
        $this->assertSame(1, JobReport::where('booking_id', $b->id)->count());
    }

    public function test_report_content_and_missing_details(): void
    {
        $this->actingAs($this->user('Operations'));
        $b = $this->completedBooking();
        $html = view('pdf.job-report', JobReports::data($b, auth()->user()) + ['number' => 'JR-TEST', 'version' => 1, 'asPdf' => false])->render();
        $this->assertStringContainsString($b->reference, $html);
        $this->assertStringContainsString($b->customer->name, $html);
        $this->assertStringContainsString('10,300', $html);
        $this->assertStringContainsString('No signature is captured', $html);
        $this->assertSame(['Return photos (at least 1)'], JobReports::missing($b)); // default minimum photos
        $this->settings(['return_min_photos' => 0]);
        $this->assertSame([], JobReports::missing($b->fresh()));

        // A job with empty return details is listed as incomplete, never "complete".
        $b2 = $this->booking($this->car(), '2026-11-06 10:00', '2026-11-07 10:00');
        $missing = JobReports::missing($b2);
        $this->assertContains('Job not completed yet', $missing);
        $this->assertContains('Handover not recorded', $missing);
        $this->assertContains('Odometer at return', $missing);
        $this->assertTrue(collect($missing)->contains(fn ($m) => str_starts_with($m, 'Balance still open')));
    }

    public function test_making_it_again_keeps_every_version_and_never_completes_the_job(): void
    {
        $this->actingAs($this->user('Operations'));
        $b = $this->completedBooking();
        $v2 = JobReports::generate($b->fresh(), auth()->user(), 'Return details corrected');

        $this->assertSame(2, $v2->version);
        $this->assertSame(2, JobReport::where('booking_id', $b->id)->count());
        $this->assertSame(1, JobReport::where('booking_id', $b->id)->where('is_current', true)->count());
        $this->assertTrue(JobReports::current($b)->is($v2));
        Storage::disk('private')->assertExists(JobReport::where('booking_id', $b->id)->where('version', 1)->value('path'));

        $open = $this->booking($this->car(), '2026-11-06 10:00', '2026-11-07 10:00');
        $r = JobReports::generate($open, auth()->user(), 'Early copy');
        $this->assertNotSame(BookingStatus::Completed, $open->fresh()->status);
        $this->assertContains('Job not completed yet', $r->missing);
        $this->assertFalse($r->isComplete());
    }

    public function test_access_staff_customer_driver_and_download_is_logged(): void
    {
        $this->actingAs($this->user('Operations'));
        $b = $this->completedBooking();
        $r = JobReports::current($b);
        $v2 = JobReports::generate($b->fresh(), auth()->user(), 'Again');

        $res = $this->get($r->url())->assertOk();
        // Never stored by browsers or proxies (a Livewire test earlier in the same run can add more directives).
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertTrue(AuditLog::where('event', 'download')->where('note', 'like', '%'.$r->number.'%')->exists());

        $mine = Accounts::customerAccount($b->customer, 'mine@example.com');
        $this->actingAs($mine);
        $this->get($v2->url())->assertOk();
        $this->get($r->url())->assertNotFound(); // customers see the latest only

        $this->actingAs($this->user('Operations'));
        $other = $this->completedBooking();
        $this->actingAs($mine);
        $this->get(JobReports::current($other)->url())->assertNotFound();

        $this->actingAs($this->user('Driver'));
        $this->get($v2->url())->assertForbidden();

        auth()->logout();
        $this->get($v2->url())->assertForbidden();
    }

    public function test_photos_are_embedded_small(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not installed');
        }
        $this->actingAs($this->user('Operations'));
        $b = $this->booking($this->car(), '2026-11-02 10:00', '2026-11-04 10:00');
        $b->addMedia(UploadedFile::fake()->image('front.jpg', 1200, 800))->toMediaCollection('delivery');
        $photos = JobReports::photos($b->fresh());
        $this->assertCount(1, $photos);
        $this->assertSame('Handover', $photos[0]['label']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $photos[0]['src']);
        [$w] = getimagesizefromstring(base64_decode(substr($photos[0]['src'], 23)));
        $this->assertSame(360, $w);
    }
}
