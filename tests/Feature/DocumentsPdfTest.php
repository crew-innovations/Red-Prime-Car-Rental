<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Support\Accounts;
use App\Support\BookingFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 7: quotation, confirmation, invoice / credit note, receipt and return statement PDFs. */
class DocumentsPdfTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        $this->settings(['company_legal_name' => 'Red Prime Car Rental L.L.C', 'company_trn' => '100123456700003', 'company_address' => 'Al Quoz, Dubai']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function paidBooking(array $attrs = []): Booking
    {
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', $attrs);
        $b->recordPayment(500, 'card');

        return $b->fresh();
    }

    protected function assertPdf($response): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_staff_open_every_document_as_a_real_pdf(): void
    {
        $this->actingAs($this->user('Manager'));
        $b = $this->paidBooking();
        $q = Quotation::create(['customer_id' => $b->customer_id, 'service_type' => ServiceType::Car, 'bookable_id' => $b->bookable_id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => '2026-11-20 10:00', 'end_at' => '2026-11-22 10:00', 'valid_until' => '2026-11-08', 'terms' => 'Fuel same level']);
        $invoice = Invoice::issueFor($b);
        BookingFlow::deliver($b, ['delivered_at' => now(), 'delivery_km' => 10000]);
        $receipt = $b->transactions()->where('is_balance', false)->where('status', PaymentStatus::Paid)->first();

        $this->assertPdf($this->get(route('pdf.quotation', $q)));
        $this->assertPdf($this->get(route('pdf.confirmation', $b)));
        $this->assertPdf($this->get(route('pdf.invoice', $invoice)));
        $this->assertPdf($this->get(route('pdf.receipt', $receipt)));
        $this->assertPdf($this->get(route('pdf.statement', $b)));
        $this->get(route('pdf.quotation', $q).'?download=1')->assertHeader('Content-Disposition', 'attachment; filename="Quotation '.$q->number.'.pdf"');
    }

    public function test_quotation_pdf_from_the_secure_link_only_once_sent(): void
    {
        $this->actingAs($this->user('Sales'));
        $car = $this->car();
        $q = Quotation::create(['customer_id' => $this->customer()->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => '2026-11-20 10:00', 'end_at' => '2026-11-22 10:00', 'valid_until' => '2026-11-08']);
        auth()->logout();
        $this->get('/quote/'.$q->public_token.'/pdf')->assertNotFound();
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        $this->assertPdf($this->get('/quote/'.$q->public_token.'/pdf'));
    }

    public function test_agents_and_customers_only_get_their_own_documents(): void
    {
        $agent = $this->user('Agent');
        $other = $this->user('Agent');
        $this->actingAs($agent);
        $mine = $this->paidBooking();

        $this->actingAs($other);
        $this->get(route('pdf.confirmation', $mine))->assertNotFound();

        $customerAccount = Accounts::customerAccount($mine->customer, 'mine@example.com');
        $theirs = $this->paidBooking();
        $this->actingAs($customerAccount);
        $this->assertPdf($this->get(route('pdf.confirmation', $mine)));
        $this->get(route('pdf.confirmation', $theirs))->assertNotFound();
        $this->get(route('pdf.receipt', $theirs->transactions()->where('is_balance', false)->first()))->assertNotFound();

        $this->actingAs($this->user('Driver'));
        $this->get(route('pdf.confirmation', $mine))->assertForbidden();

        auth()->logout();
        $this->get(route('pdf.confirmation', $mine))->assertForbidden();
    }

    public function test_invoice_numbers_tax_wording_credit_notes_and_void(): void
    {
        $this->actingAs($this->user('Finance'));
        $a = $this->paidBooking();
        $b = $this->paidBooking();

        $first = Invoice::issueFor($a);
        $second = Invoice::issueFor($b);
        $this->assertSame(['INV-00001', 'INV-00002'], [$first->number, $second->number]);
        $this->assertFalse($first->is_tax_invoice);
        $this->assertSame('Invoice', $first->title());
        $this->assertArrayNotHasKey('trn', $first->seller, 'no TRN while VAT is off');
        $html = view('pdf.invoice', ['i' => $first, 'asPdf' => false])->render();
        $this->assertStringContainsString('Not a tax invoice', $html);
        $this->assertStringNotContainsString('100123456700003', $html);

        try {
            Invoice::issueFor($a);
            $this->fail('second open invoice for the same booking');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice', $e->errors());
        }

        // Credit note up to the invoice total, then void.
        $cn = Invoice::creditFor($first, 300, 'Early return');
        $this->assertSame('CN-00001', $cn->number);
        try {
            Invoice::creditFor($first, (float) $first->total, 'Too much');
            $this->fail('credit above the invoice');
        } catch (ValidationException) {
            $this->assertSame(1, $first->creditNotes()->count());
        }
        $first->void('Wrong customer');
        $this->assertSame('void', $first->fresh()->status);
        $this->assertSame('INV-00003', Invoice::issueFor($a->fresh())->number, 'a new one can be issued after voiding');

        // VAT on: a tax invoice with the TRN.
        $this->settings(['vat_enabled' => true, 'vat_rate' => 5]);
        $c = $this->paidBooking(['tax_rate' => 5]);
        $tax = Invoice::issueFor($c);
        $this->assertTrue($tax->is_tax_invoice);
        $this->assertSame('Tax Invoice', $tax->title());
        $this->assertStringContainsString('100123456700003', view('pdf.invoice', ['i' => $tax, 'asPdf' => false])->render());
    }

    public function test_confirmation_only_for_bookings_that_are_on(): void
    {
        $this->actingAs($this->user('Manager'));
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['status' => BookingStatus::Inquiry]);
        $this->get(route('pdf.confirmation', $b))->assertNotFound();
        try {
            Invoice::issueFor($b);
            $this->fail('invoice for an inquiry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice', $e->errors());
        }
    }
}
