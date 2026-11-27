<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\QuotationResource\Pages\EditQuotation;
use App\Models\CustomStatus;
use App\Support\CustomStatuses;
use App\Models\Lead;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_an_admin_adds_a_custom_status_without_changing_the_real_one(): void
    {
        $this->actingAs($this->user('Admin'));
        $lead = Lead::create(['name' => 'Omar', 'service_type' => 'car', 'assigned_to' => auth()->id()]);
        $stage = $lead->stage;
        $status = $lead->status;

        Livewire::test(EditLead::class, ['record' => $lead->id])
            ->callAction('customStatus', ['new_name' => 'VIP follow-up', 'color' => 'warning'])
            ->assertHasNoActionErrors();

        $lead->refresh();
        $this->assertSame($stage, $lead->stage);
        $this->assertSame($status, $lead->status);
        $this->assertSame('VIP follow-up', $lead->customStatus->name);
        $this->assertSame('lead', $lead->customStatus->record_type);
        $this->assertSame('warning', $lead->customStatus->color);
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
            ->callAction('customStatus', ['custom_status_id' => $made->id])
            ->assertHasNoActionErrors();
        $this->assertSame($made->id, $booking->fresh()->custom_status_id);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        try {
            CustomStatuses::apply($booking, 'booking', ['new_name' => 'Not allowed']);
            $this->fail('A salesperson added a status.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Livewire::test(EditBooking::class, ['record' => $booking->id])
            ->callAction('customStatus', ['new_name' => 'Not allowed']);
        $this->assertNull(CustomStatus::query()->where('name', 'Not allowed')->first());
        $this->assertSame($made->id, $booking->fresh()->custom_status_id);
    }

    public function test_a_status_stays_on_its_own_record_type(): void
    {
        $this->actingAs($this->user('Admin'));
        $bookingStatus = CustomStatus::create(['record_type' => 'booking', 'name' => 'Hold', 'color' => 'gray']);
        $quote = Quotation::create(['service_type' => ServiceType::Car, 'status' => QuotationStatus::Draft]);

        Livewire::test(EditQuotation::class, ['record' => $quote->id])
            ->callAction('customStatus', ['custom_status_id' => $bookingStatus->id])
            ->assertHasActionErrors(['custom_status_id']);
        $this->assertNull($quote->fresh()->custom_status_id);

        Livewire::test(EditQuotation::class, ['record' => $quote->id])
            ->callAction('customStatus', ['new_name' => 'Sent by hand', 'color' => 'info'])
            ->assertHasNoActionErrors();
        $quote->refresh();
        $this->assertSame(QuotationStatus::Draft, $quote->status);
        $this->assertSame('quotation', $quote->customStatus->record_type);
    }
}
