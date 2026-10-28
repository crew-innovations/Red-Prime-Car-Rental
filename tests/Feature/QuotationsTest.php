<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Pipeline;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\QuotationResource\Pages\CreateQuotation;
use App\Filament\Resources\QuotationResource\Pages\EditQuotation;
use App\Filament\Resources\QuotationResource\Pages\ListQuotations;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Quotation;
use App\Support\Availability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Checklist 5 (quotations). */
class QuotationsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        $this->actingAs($this->user('Sales'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function draftFromLead(): array
    {
        $car = $this->car();
        $lead = Lead::create(['name' => 'Rashid Omar', 'phone' => '0502223344', 'pipeline' => Pipeline::Car, 'service_type' => ServiceType::Car,
            'interested_in_type' => 'car', 'interested_in_id' => $car->id, 'preferred_date' => '2026-11-10', 'end_date' => '2026-11-12']);

        Livewire::test(CreateQuotation::class, ['lead' => (string) $lead->id])
            ->assertFormSet(['lead_id' => $lead->id, 'bookable_id' => $car->id, 'base_amount' => 2000.0])
            ->fillForm(['discount' => 100, 'adjustment_reason' => 'Returning customer', 'extras' => [['label' => 'Delivery & pick-up', 'amount' => 150]], 'valid_until' => '2026-11-05', 'terms' => 'Our terms'])
            ->call('create')
            ->assertHasNoFormErrors();

        return [$car, $lead, Quotation::latest('id')->first()];
    }

    public function test_quote_is_prefilled_from_the_lead_and_totals_are_right(): void
    {
        [, $lead, $q] = $this->draftFromLead();
        $this->assertSame(QuotationStatus::Draft, $q->status);
        $this->assertSame('QT-'.str_pad($q->id, 5, '0', STR_PAD_LEFT), $q->number);
        $this->assertSame('2050.00', $q->total, '2000 + 150 delivery - 100 discount');
        $this->assertSame($lead->id, $q->lead_id);
        $this->assertTrue($lead->quotations()->whereKey($q->id)->exists());
    }

    public function test_vat_is_added_only_when_switched_on(): void
    {
        $this->settings(['vat_enabled' => true, 'vat_rate' => 5, 'vat_inclusive' => false]);
        [, , $q] = $this->draftFromLead();
        $this->assertSame('5.00', $q->tax_rate);
        $this->assertSame('102.50', $q->tax_amount);
        $this->assertSame('2152.50', $q->total);
    }

    public function test_sending_locks_the_price_and_moves_the_lead(): void
    {
        [, $lead, $q] = $this->draftFromLead();
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('send')->assertHasNoActionErrors();

        $q->refresh();
        $this->assertSame(QuotationStatus::Sent, $q->status);
        $this->assertNotNull($q->sent_at);
        $this->assertSame('quotation_sent', $lead->fresh()->stage);

        // The form refuses to save a sent quote...
        Livewire::test(EditQuotation::class, ['record' => $q->id])->call('save')->assertForbidden();

        // ...and so does the model, from anywhere.
        try {
            $q->update(['base_amount' => 1]);
            $this->fail('price changed on a sent quote');
        } catch (ValidationException) {
            $this->assertSame('2050.00', $q->fresh()->total);
        }

        // Revise unlocks it (and the change is in the history).
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('revise');
        $q->refresh()->update(['discount' => 200]);
        $this->assertSame(QuotationStatus::Negotiation, $q->status);
        $this->assertSame('1950.00', $q->fresh()->total);
        $this->assertTrue($q->auditLogs()->where('event', 'updated')->exists());
    }

    public function test_accepting_does_not_reserve_the_car(): void
    {
        [$car, , $q] = $this->draftFromLead();
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('accept');

        $this->assertSame(QuotationStatus::Accepted, $q->fresh()->status);
        $this->assertSame(0, Booking::count());
        $this->assertNull(Availability::assetConflict('car', $car->id, $q->start_at, $q->end_at));
    }

    public function test_convert_creates_the_booking_at_the_agreed_price_after_rechecking(): void
    {
        [$car, $lead, $q] = $this->draftFromLead();
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        $q->update(['status' => QuotationStatus::Accepted, 'accepted_at' => now()]);
        $car->update(['daily_price' => 5000]); // price list changed after the quote was agreed

        Livewire::test(EditQuotation::class, ['record' => $q->id])
            ->callAction('convert', data: ['status' => 'confirmed', 'advance' => 500, 'method' => 'card'])
            ->assertHasNoActionErrors();

        $b = Booking::first();
        $this->assertNotNull($b);
        $this->assertSame($q->id, $b->quotation_id);
        $this->assertSame('2050.00', $b->total, 'agreed price kept, not the new price list');
        $this->assertSame('500.00', $b->paid_amount);
        $this->assertSame($b->id, $q->fresh()->booking_id);
        $this->assertNotNull($b->customer_id);
        $this->assertSame($b->customer_id, $lead->fresh()->customer_id);
    }

    public function test_convert_fails_cleanly_when_the_car_was_booked_meanwhile(): void
    {
        [$car, , $q] = $this->draftFromLead();
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        $q->update(['status' => QuotationStatus::Accepted, 'accepted_at' => now()]);
        $this->booking($car, '2026-11-11 10:00', '2026-11-13 10:00');

        Livewire::test(EditQuotation::class, ['record' => $q->id])
            ->callAction('convert', data: ['status' => 'confirmed']);

        $this->assertSame(1, Booking::count(), 'only the other booking');
        $this->assertSame(QuotationStatus::Accepted, $q->fresh()->status);
        $this->assertNull($q->fresh()->booking_id);
    }

    public function test_reject_needs_a_reason_and_overdue_quotes_expire(): void
    {
        [, , $q] = $this->draftFromLead();
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('reject', data: ['reason' => ''])->assertHasActionErrors(['reason']);
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('reject', data: ['reason' => 'Price too high']);
        $this->assertSame(QuotationStatus::Rejected, $q->fresh()->status);

        [, , $q2] = $this->draftFromLead();
        $q2->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        Carbon::setTestNow('2026-11-07 09:00:00');
        Livewire::test(ListQuotations::class);
        $this->assertSame(QuotationStatus::Expired, $q2->fresh()->status);
        $this->assertSame(QuotationStatus::Rejected, $q->fresh()->status, 'closed quotes are left alone');
    }

    public function test_public_page_shows_branding_terms_and_price(): void
    {
        $this->settings(['company_name' => 'RED PRIME RENTALS', 'company_phone' => '+971 4 000 0000']);
        [, , $q] = $this->draftFromLead();
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        auth()->logout();

        $this->get($q->publicUrl())->assertOk()
            ->assertSee('RED PRIME RENTALS')->assertSee('Our terms')->assertSee('2,050.00')->assertSee($q->number)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_duplicate_makes_a_new_draft(): void
    {
        [, , $q] = $this->draftFromLead();
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        Livewire::test(ListQuotations::class)->callTableAction('duplicate', $q);
        $copy = Quotation::latest('id')->first();
        $this->assertNotSame($q->id, $copy->id);
        $this->assertSame(QuotationStatus::Draft, $copy->status);
        $this->assertNotSame($q->public_token, $copy->public_token);
        $this->assertSame($q->total, $copy->total);
    }

    public function test_finance_cannot_create_quotes(): void
    {
        $this->actingAs($this->user('Finance'));
        $this->get('/crm/quotations/create')->assertForbidden();
    }

    public function test_drivers_cannot_see_quotes(): void
    {
        $this->actingAs($this->user('Driver'));
        $this->get('/crm/quotations')->assertForbidden();
    }
}
