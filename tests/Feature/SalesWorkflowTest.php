<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Enums\DriveMode;
use App\Enums\Pipeline;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\QuotationResource\Pages\EditQuotation;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\DocumentRequest;
use App\Models\Lead;
use App\Models\Quotation;
use App\Support\Sales\BookingChecks;
use App\Support\Sales\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 3: inquiry → agent → quotation → customer accepts → documents → booking. */
class SalesWorkflowTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        $this->settings(['doc_requirements' => ['self_drive' => ['emirates_id', 'driving_licence']]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + ['name' => 'Sara Khan', 'phone' => '0501112233', 'email' => 'sara@example.com', 'pipeline' => Pipeline::Car, 'service_type' => ServiceType::Car]);
    }

    protected function sentQuote(Lead $lead, array $attrs = []): Quotation
    {
        $car = $this->car();
        $q = Quotation::create($attrs + [
            'lead_id' => $lead->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'valid_until' => '2026-11-06', 'terms' => 'Our terms',
        ]);
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);

        return $q->fresh();
    }

    /* ---------- 3.1 ownership ---------- */

    public function test_assigned_agent_accepts_the_lead_and_others_cannot_see_it(): void
    {
        $manager = $this->user('Manager');
        $agent = $this->user('Agent');
        $other = $this->user('Agent');

        $this->actingAs($manager);
        $lead = $this->lead(['assigned_to' => $agent->id]);
        $this->assertSame('pending', $lead->assignment_status);
        $this->assertSame(1, $agent->notifications()->count(), 'the agent is told');

        $this->actingAs($other);
        $this->get('/crm/leads/'.$lead->id.'/edit')->assertNotFound();

        $this->actingAs($agent);
        Livewire::test(\App\Filament\Resources\LeadResource\Pages\EditLead::class, ['record' => $lead->id])->callAction('acceptLead');
        $lead->refresh();
        $this->assertSame('accepted', $lead->assignment_status);
        $this->assertNotNull($lead->accepted_at);
    }

    public function test_agent_can_hand_a_lead_back(): void
    {
        $manager = $this->user('Manager');
        $agent = $this->user('Agent');
        $this->actingAs($manager);
        $lead = $this->lead(['assigned_to' => $agent->id]);

        $this->actingAs($agent);
        Livewire::test(\App\Filament\Resources\LeadResource\Pages\EditLead::class, ['record' => $lead->id])
            ->callAction('handBack', ['reason' => 'Fully booked this week']);
        $this->assertNull($lead->fresh()->assigned_to);
        $this->assertSame(1, $manager->notifications()->count());
    }

    public function test_a_lead_you_create_for_yourself_is_already_accepted(): void
    {
        $agent = $this->user('Agent');
        $this->actingAs($agent);
        $this->assertSame('accepted', $this->lead(['assigned_to' => $agent->id])->assignment_status);
    }

    /* ---------- 3.4 customer accepts ---------- */

    public function test_customer_accepts_on_the_link_and_the_profile_and_document_request_follow(): void
    {
        $agent = $this->user('Agent');
        $this->actingAs($agent);
        $lead = $this->lead(['assigned_to' => $agent->id]);
        $q = $this->sentQuote($lead);
        auth()->logout();

        $this->get('/quote/'.$q->public_token)->assertOk()->assertSee('Accept quotation');
        $this->post('/quote/'.$q->public_token.'/accept', ['name' => 'Sara Khan'])->assertSessionHasErrors('agree');
        $this->assertSame(QuotationStatus::Sent, $q->fresh()->status);

        $this->post('/quote/'.$q->public_token.'/accept', ['name' => 'Sara Khan', 'agree' => '1'], ['REMOTE_ADDR' => '10.1.2.3'])
            ->assertRedirect('/quote/'.$q->public_token)->assertSessionHas('accepted')->assertSessionHas('documents_url');

        $q->refresh();
        $this->assertSame(QuotationStatus::Accepted, $q->status);
        $this->assertSame('link', $q->accepted_via);
        $this->assertSame('Sara Khan', $q->accepted_name);
        $this->assertSame('10.1.2.3', $q->accepted_ip);
        $this->assertEquals(2000, $q->accepted_terms['total']);
        $this->assertSame('Our terms', $q->accepted_terms['terms']);

        // Customer profile created from the lead and linked; no booking and no hold yet.
        $this->assertNotNull($q->customer_id);
        $this->assertSame($q->customer_id, $lead->fresh()->customer_id);
        $this->assertSame(0, Booking::count());

        // Documents asked for, agent told.
        $request = DocumentRequest::find($q->document_request_id);
        $this->assertEqualsCanonicalizing(['emirates_id', 'driving_licence'], $request->types);
        $this->assertTrue($agent->notifications()->get()->contains(fn ($n) => str_contains($n->data['title'] ?? '', 'accepted')));

        // Accepting again does nothing.
        $this->post('/quote/'.$q->public_token.'/accept', ['name' => 'Sara Khan', 'agree' => '1'])->assertSessionHasErrors('quotation');
    }

    public function test_an_existing_customer_is_linked_not_duplicated(): void
    {
        $existing = Customer::create(['name' => 'Sara K.', 'phone' => '0501112233', 'email' => 'sara@example.com']);
        $this->actingAs($this->user('Sales'));
        $q = $this->sentQuote($this->lead());
        \App\Support\Sales\QuotationDecision::accept($q, 'link', 'Sara');
        $this->assertSame($existing->id, $q->fresh()->customer_id);
        $this->assertSame(1, Customer::count());
    }

    public function test_expired_or_revised_quotes_cannot_be_accepted_and_decline_is_recorded(): void
    {
        $this->actingAs($this->user('Sales'));
        $q = $this->sentQuote($this->lead());

        Carbon::setTestNow('2026-11-08 09:00:00');
        try {
            \App\Support\Sales\QuotationDecision::accept($q, 'link', 'Sara');
            $this->fail('expired quote accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('expired', collect($e->errors())->flatten()->first());
        }
        Carbon::setTestNow('2026-11-02 09:00:00');

        $q->update(['status' => QuotationStatus::Negotiation]);
        $this->assertNotNull($q->fresh()->acceptProblem());

        $q->update(['status' => QuotationStatus::Sent]);
        auth()->logout();
        $this->post('/quote/'.$q->public_token.'/decline', ['reason' => 'Found a cheaper car'])->assertSessionHas('declined');
        $q->refresh();
        $this->assertSame(QuotationStatus::Rejected, $q->status);
        $this->assertSame('link', $q->rejected_via);
        $this->assertSame('Found a cheaper car', $q->rejection_reason);
    }

    public function test_customer_account_can_only_accept_its_own_quotation(): void
    {
        $this->actingAs($this->user('Sales'));
        $mine = Customer::create(['name' => 'Mine', 'phone' => '0509990001', 'email' => 'mine@example.com']);
        $theirs = Customer::create(['name' => 'Theirs', 'phone' => '0509990002', 'email' => 'theirs@example.com']);
        $car = $this->car();
        $make = function (Customer $c) use ($car) {
            $q = Quotation::create(['customer_id' => $c->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
                'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'valid_until' => '2026-11-06']);
            $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);

            return $q;
        };
        $qMine = $make($mine);
        $qTheirs = $make($theirs);
        $account = \App\Support\Accounts::customerAccount($mine);

        $this->actingAs($account);
        $this->post('/quote/'.$qTheirs->public_token.'/accept', ['name' => 'Mine', 'agree' => '1'])->assertForbidden();
        $this->post('/quote/'.$qMine->public_token.'/accept', ['name' => 'Mine', 'agree' => '1'])->assertSessionHas('accepted');
        $this->assertSame('portal', $qMine->fresh()->accepted_via);
        $this->assertSame(QuotationStatus::Sent, $qTheirs->fresh()->status);
    }

    public function test_staff_can_record_an_acceptance_given_by_phone(): void
    {
        $this->actingAs($this->user('Sales'));
        $q = $this->sentQuote($this->lead());
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('accept', ['name' => 'Sara (by phone)'])->assertHasNoActionErrors();
        $q->refresh();
        $this->assertSame('staff', $q->accepted_via);
        $this->assertNotNull($q->customer_id);
    }

    /* ---------- 3.6 booking from the accepted quote ---------- */

    public function test_create_booking_shows_checks_and_respects_the_documents_rule(): void
    {
        $this->settings(['doc_block_confirm' => true]);
        $this->actingAs($this->user('Sales'));
        $q = $this->sentQuote($this->lead(), ['advance_required' => 500]);
        \App\Support\Sales\QuotationDecision::accept($q, 'link', 'Sara');
        $q->refresh();

        $html = BookingChecks::html(BookingChecks::forQuotation($q));
        $this->assertStringContainsString('Emirates ID', $html);
        $this->assertNotNull(BookingChecks::confirmBlocked($q));

        // Confirmed is refused while documents are missing...
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('convert', ['status' => 'confirmed', 'advance' => 500]);
        $this->assertSame(0, Booking::count());

        // ...Pending payment works, at the agreed price, linked to the quote.
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('convert', ['status' => 'pending_payment', 'advance' => 500, 'method' => 'card'])->assertHasNoActionErrors();
        $booking = Booking::first();
        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame($q->id, $booking->quotation_id);
        $this->assertSame('2000.00', $booking->total);
        $this->assertSame('500.00', $booking->paid_amount);
        $this->assertSame($booking->id, $q->fresh()->booking_id);

        // Documents approved: confirming is allowed now.
        foreach (['emirates_id', 'driving_licence'] as $type) {
            CustomerDocument::create(['customer_id' => $q->customer_id, 'type' => $type, 'status' => DocumentStatus::Approved, 'expires_on' => '2027-12-31', 'path' => 'customer-documents/'.$type.'.jpg']);
        }
        $this->assertNull(BookingChecks::confirmBlocked($q->fresh()));

        $steps = collect(Journey::forBooking($booking->fresh()))->keyBy(0);
        $this->assertSame('done', $steps['Quotation accepted'][1]);
        $this->assertSame('done', $steps['Documents'][1]);
        $this->assertSame('now', $steps['Booking confirmed'][1]);
    }

    public function test_optional_rule_bookings_only_from_an_accepted_quote(): void
    {
        $this->settings(['booking_requires_accepted_quote' => true]);
        $this->actingAs($this->user('Sales'));
        $car = $this->car();
        try {
            $this->booking($car, '2026-11-10 10:00', '2026-11-12 10:00');
            $this->fail('direct confirmed booking allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.status', $e->errors());
        }
        $this->assertSame(BookingStatus::Inquiry, $this->booking($car, '2026-11-10 10:00', '2026-11-12 10:00', ['status' => BookingStatus::Inquiry])->status);
    }

    public function test_sending_can_email_the_link(): void
    {
        Notification::fake();
        $this->actingAs($this->user('Sales'));
        $car = $this->car();
        $q = Quotation::create(['lead_id' => $this->lead()->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'valid_until' => '2026-11-06']);

        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('send', ['email' => true])->assertHasNoActionErrors();
        Notification::assertSentOnDemand(\App\Notifications\QuotationSent::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'sara@example.com');
        $this->assertNotNull($q->fresh()->emailed_at);
    }
}
