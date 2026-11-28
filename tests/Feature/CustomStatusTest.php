<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Pipeline;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\QuotationResource\Pages\CreateQuotation;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\QuotationResource\Pages\EditQuotation;
use App\Filament\Resources\QuotationResource\Pages\ListQuotations;
use App\Models\CustomStatus;
use App\Models\Lead;
use App\Models\Quotation;
use App\Support\CustomStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

class CustomStatusTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_an_admin_adds_a_status_to_the_list_without_changing_the_fixed_one(): void
    {
        $this->actingAs($this->user('Admin'));
        $source = \App\Models\LeadSource::create(['name' => 'Phone', 'slug' => 'phone', 'is_active' => true]);
        $lead = Lead::create(['name' => 'Omar', 'phone' => '0501112233', 'service_type' => 'car', 'lead_source_id' => $source->id, 'assigned_to' => auth()->id()]);
        $stage = $lead->stage;
        $status = $lead->status;

        Livewire::test(EditLead::class, ['record' => $lead->id])
            ->fillForm(['new_status' => 'VIP follow-up'])
            ->call('save')
            ->assertHasNoFormErrors();

        $lead->refresh();
        $this->assertSame($stage, $lead->stage);
        $this->assertSame($status, $lead->status);
        $this->assertSame('VIP follow-up', $lead->customStatus->name);
        $this->assertSame('lead', $lead->customStatus->record_type);

        Livewire::test(ListLeads::class, ['activeTab' => 'all'])->assertOk()->assertSee('VIP follow-up');
        Livewire::test(EditLead::class, ['record' => $lead->id])
            ->assertFormSet(['stage' => CustomStatuses::key($lead->custom_status_id)]);
    }

    public function test_staff_can_pick_a_status_but_cannot_add_one(): void
    {
        $admin = $this->user('Admin');
        $this->actingAs($admin);
        $sales = $this->user('Sales');
        $made = CustomStatus::create(['record_type' => 'booking', 'name' => 'Airport run', 'color' => 'info', 'created_by' => $admin->id]);
        $booking = $this->booking($this->car(), '2026-12-01 10:00', '2026-12-03 10:00', ['assigned_to' => $sales->id]);

        $this->actingAs($sales);
        Livewire::test(EditBooking::class, ['record' => $booking->id])
            ->fillForm(['status' => CustomStatuses::key($made->id)])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($made->id, $booking->fresh()->custom_status_id);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        Livewire::test(ListBookings::class, ['activeTab' => 'all'])->assertSee('Airport run');

        try {
            CustomStatuses::take(['new_status' => 'Not allowed', 'status' => BookingStatus::Confirmed->value], 'booking', 'status');
            $this->fail('A salesperson added a status.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull(CustomStatus::query()->where('name', 'Not allowed')->first());
        $this->assertSame($made->id, $booking->fresh()->custom_status_id);
    }

    public function test_a_status_stays_on_its_own_record_type(): void
    {
        $this->actingAs($this->user('Admin'));
        $bookingStatus = CustomStatus::create(['record_type' => 'booking', 'name' => 'Hold', 'color' => 'gray']);
        $car = $this->car();
        $start = now()->addDays(10)->toDateString();
        $end = now()->addDays(12)->toDateString();
        $lead = Lead::create(['name' => 'Rashid Omar', 'phone' => '0502223344', 'pipeline' => Pipeline::Car, 'service_type' => ServiceType::Car,
            'interested_in_type' => 'car', 'interested_in_id' => $car->id, 'preferred_date' => $start, 'end_date' => $end]);
        Livewire::test(CreateQuotation::class, ['lead' => (string) $lead->id])
            ->fillForm(['valid_until' => now()->addDays(20)->toDateString(), 'terms' => 'Our terms'])
            ->call('create')
            ->assertHasNoFormErrors();
        $quote = Quotation::latest('id')->first();

        try {
            CustomStatuses::take(['status_choice' => CustomStatuses::key($bookingStatus->id)], 'quotation', 'status_choice', $quote->status->value, false, $quote);
            $this->fail('A booking status was accepted on a quotation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status_choice', $e->errors());
        }
        Livewire::test(EditQuotation::class, ['record' => $quote->id])
            ->fillForm(['status_choice' => CustomStatuses::key($bookingStatus->id)])
            ->call('save');
        $this->assertNull($quote->fresh()->custom_status_id);
        $this->assertSame(QuotationStatus::Draft, $quote->fresh()->status);

        Livewire::test(EditQuotation::class, ['record' => $quote->id])
            ->fillForm(['new_status' => 'Sent by hand'])
            ->call('save')
            ->assertHasNoFormErrors();
        $quote->refresh();
        $this->assertSame(QuotationStatus::Draft, $quote->status);
        $this->assertSame('quotation', $quote->customStatus->record_type);
        Livewire::test(ListQuotations::class)->assertOk()->assertSee('Sent by hand');
    }

    public function test_the_lead_booking_and_quotation_lists_open(): void
    {
        $this->actingAs($this->user('Admin'));
        $lead = Lead::create(['name' => 'Omar', 'service_type' => 'car', 'assigned_to' => auth()->id()]);
        $car = $this->car();
        $booking = $this->booking($car, '2026-12-01 10:00', '2026-12-03 10:00');
        $quote = Quotation::create(['service_type' => ServiceType::Car, 'status' => QuotationStatus::Draft]);

        Livewire::test(ListLeads::class, ['activeTab' => 'all'])->assertOk()->assertSee($lead->name);
        Livewire::test(ListBookings::class, ['activeTab' => 'all'])->assertOk()->assertSee($booking->reference);
        Livewire::test(ListQuotations::class)->assertOk()->assertSee($quote->number);
    }
}
