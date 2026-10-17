<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Filament\Resources\BookingResource\BookingActions;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\DocumentsRelationManager;
use App\Models\CustomerDocument;
use App\Models\DocumentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Checklist 13 (customer documents) and private files (16). */
class DocumentsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('uploads');
        // Same signed-link builder the app registers for the real private disk.
        Storage::disk('private')->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expiration) => \Illuminate\Support\Facades\URL::temporarySignedRoute('private-file', $expiration, ['path' => $path])
        );
        $this->seedRoles();
    }

    public function test_upload_link_accepts_only_requested_types_and_safe_files(): void
    {
        $customer = $this->customer();
        $link = DocumentRequest::issue($customer, ['passport', 'driving_licence'], 72);
        $token = basename(parse_url($link->url(), PHP_URL_PATH));

        $this->assertNotSame($token, $link->token_hash, 'only a hash of the token is stored');
        $this->get($link->url())->assertOk()->assertSee('Passport')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->post($link->url(), ['type' => 'passport', 'file' => UploadedFile::fake()->create('passport.pdf', 300, 'application/pdf')])->assertRedirect();
        $doc = CustomerDocument::first();
        $this->assertSame('passport', $doc->type);
        $this->assertSame(DocumentStatus::Received, $doc->status);
        Storage::disk('private')->assertExists($doc->path);
        $this->assertSame([], Storage::disk('uploads')->allFiles(), 'nothing in the public folder');

        $this->post($link->url(), ['type' => 'visa', 'file' => UploadedFile::fake()->create('v.pdf', 10, 'application/pdf')])->assertSessionHasErrors('type');
        $this->post($link->url(), ['type' => 'passport', 'file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('file');
        $this->post($link->url(), ['type' => 'passport', 'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertSame(1, CustomerDocument::count());
        $this->assertSame(1, $link->fresh()->upload_count);
    }

    public function test_expired_or_switched_off_links_stop_working(): void
    {
        $link = DocumentRequest::issue($this->customer(), ['passport'], 1);
        Carbon::setTestNow(now()->addHours(2));
        $this->get($link->url())->assertStatus(410);
        Carbon::setTestNow();

        $link2 = DocumentRequest::issue($this->customer(), ['passport'], 72);
        $link2->update(['revoked_at' => now()]);
        $this->post($link2->url(), ['type' => 'passport', 'file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')])->assertStatus(410);
        $this->get('/upload/not-a-token')->assertStatus(410);
    }

    public function test_private_files_need_a_signed_link_and_a_logged_in_user(): void
    {
        Storage::disk('private')->put('customers/1/p.pdf', 'secret');
        $doc = CustomerDocument::create(['customer_id' => $this->customer()->id, 'type' => 'passport', 'path' => 'customers/1/p.pdf']);
        $url = $doc->viewUrl();

        $this->get($url)->assertForbidden(); // guest
        $this->get('/crm/private-file/customers/1/p.pdf')->assertForbidden(); // no signature

        $this->actingAs($this->user('Sales'));
        $this->get($url)->assertOk();
    }

    public function test_inactive_user_cannot_open_private_files(): void
    {
        Storage::disk('private')->put('customers/1/p.pdf', 'secret');
        $doc = CustomerDocument::create(['customer_id' => $this->customer()->id, 'type' => 'passport', 'path' => 'customers/1/p.pdf']);
        $this->actingAs($this->user('Sales', ['is_active' => false]));
        $this->get($doc->viewUrl())->assertForbidden();
    }

    public function test_review_approve_and_reject(): void
    {
        $this->actingAs($this->user('Operations'));
        $customer = $this->customer();
        $a = CustomerDocument::create(['customer_id' => $customer->id, 'type' => 'passport', 'path' => 'x.pdf']);
        $b = CustomerDocument::create(['customer_id' => $customer->id, 'type' => 'driving_licence', 'path' => 'y.pdf']);

        $rm = Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class]);
        $rm->callTableAction('approve', $a, data: ['expires_on' => '2030-01-01'])->assertHasNoTableActionErrors();
        $rm->callTableAction('reject', $b, data: ['status' => 'replacement', 'note' => 'Photo not clear'])->assertHasNoTableActionErrors();

        $this->assertSame(DocumentStatus::Approved, $a->fresh()->status);
        $this->assertSame('2030-01-01', $a->fresh()->expires_on->toDateString());
        $this->assertSame(DocumentStatus::Replacement, $b->fresh()->status);
        $this->assertSame('Photo not clear', $b->fresh()->review_note);
    }

    public function test_finance_does_not_see_customer_documents(): void
    {
        $this->actingAs($this->user('Finance'));
        $this->assertFalse(DocumentsRelationManager::canViewForRecord($this->customer(), EditCustomer::class));
    }

    public function test_required_documents_block_only_the_handover_when_switched_on(): void
    {
        $this->settings(['doc_requirements' => ['self_drive' => ['passport', 'driving_licence']], 'doc_block_handover' => false]);
        $customer = $this->customer();
        $booking = $this->booking($this->car(), now()->addDay(), now()->addDays(3), ['customer_id' => $customer->id]);

        $this->assertSame(BookingStatus::Confirmed, $booking->status, 'booking can be confirmed without documents');
        $this->assertSame(['passport', 'driving_licence'], array_keys($booking->documentChecklist()));
        $this->assertSame([], BookingActions::handoverBlockers($booking), 'not blocking while the setting is off');

        $this->settings(['doc_block_handover' => true]);
        $this->assertCount(2, BookingActions::handoverBlockers($booking->fresh()));

        CustomerDocument::create(['customer_id' => $customer->id, 'type' => 'passport', 'path' => 'p', 'status' => DocumentStatus::Approved]);
        CustomerDocument::create(['customer_id' => $customer->id, 'type' => 'driving_licence', 'path' => 'l', 'status' => DocumentStatus::Approved, 'expires_on' => now()->subDay()]);
        $blockers = BookingActions::handoverBlockers($booking->fresh());
        $this->assertCount(1, $blockers, 'expired licence still blocks');
        $this->assertStringContainsString('expired', strtolower($blockers[0]));
    }
}
