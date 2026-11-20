<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\Pipeline;
use App\Enums\ServiceType;
use App\Filament\Pages\PipelineBoard;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\CreateLead;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Checklist 1 (leads & pipeline) and 2 (customers). */
class LeadsAndCustomersTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->user('Sales'));
    }

    public function test_staff_create_a_lead_and_it_starts_in_the_right_pipeline(): void
    {
        $this->seed(\Database\Seeders\LeadSourceSeeder::class);
        Livewire::test(CreateLead::class)
            // 20 Nov (item 11): the contact details are entered in the customer form (same as New Booking).
            ->fillForm(['lead_source_id' => \App\Models\LeadSource::value('id'), 'customer' => ['type' => 'individual', 'name' => 'Omar Khalid', 'phone' => '050 111 2233'], 'service_type' => ServiceType::Yacht->value, 'pipeline' => Pipeline::Yacht->value, 'stage' => 'new'])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::firstWhere('name', 'Omar Khalid');
        $this->assertSame(Pipeline::Yacht, $lead->pipeline);
        $this->assertSame('new', $lead->stage);
        $this->assertSame(LeadStatus::New, $lead->status);
    }

    public function test_each_pipeline_has_its_own_stages(): void
    {
        $this->assertArrayHasKey('vehicle_delivered', Pipeline::Car->stages());
        $this->assertArrayHasKey('charter_completed', Pipeline::Yacht->stages());
        $this->assertArrayHasKey('active_account', Pipeline::B2b->stages());
        $this->assertSame(Pipeline::B2b, Lead::create(['name' => 'Acme', 'phone' => '0501231231', 'pipeline' => Pipeline::B2b])->pipeline);
    }

    public function test_possible_duplicate_is_flagged_by_phone_in_any_format_and_by_email(): void
    {
        $this->customer(['name' => 'Sara Ali', 'phone' => '+971 50 555 1234', 'email' => 'sara@example.com']);
        $hint = (new \ReflectionMethod(LeadResource::class, 'existingContactHint'))->invoke(null, '0505551234', null);
        $this->assertNotNull($hint, 'local format 050… matches +971 50 …');
        $this->assertStringContainsString('Sara Ali', $hint);
        $this->assertNotNull((new \ReflectionMethod(LeadResource::class, 'existingContactHint'))->invoke(null, 'sara@example.com', null));
        $this->assertNull((new \ReflectionMethod(LeadResource::class, 'existingContactHint'))->invoke(null, '0509999999', null));
    }

    public function test_moving_to_a_won_stage_links_the_existing_customer_instead_of_creating_a_duplicate(): void
    {
        $existing = $this->customer(['phone' => '+971 50 777 8899']);
        $lead = Lead::create(['name' => 'Same Person', 'phone' => '00971507778899', 'pipeline' => Pipeline::Car]);

        Livewire::test(PipelineBoard::class)->call('moveLead', $lead->id, 'confirmed');

        $lead->refresh();
        $this->assertSame(LeadStatus::Converted, $lead->status);
        $this->assertSame($existing->id, $lead->customer_id);
        $this->assertSame(1, Customer::count());
    }

    public function test_won_stage_creates_a_customer_when_none_exists_and_keeps_lead_history(): void
    {
        $lead = Lead::create(['name' => 'New Buyer', 'phone' => '0501112299', 'email' => 'nb@example.com', 'pipeline' => Pipeline::Car]);
        $lead->activities()->create(['type' => 'call', 'body' => 'Called about the G63', 'user_id' => auth()->id()]);

        $lead->update(['stage' => 'confirmed']);

        $customer = $lead->fresh()->customer;
        $this->assertNotNull($customer);
        $this->assertSame('nb@example.com', $customer->email);
        $this->assertSame(1, $lead->activities()->count(), 'activities stay on the lead');
        $this->assertTrue($customer->leads()->whereKey($lead->id)->exists(), 'customer shows the lead');
    }

    public function test_lost_needs_a_reason_on_the_board_and_on_the_model(): void
    {
        $lead = Lead::create(['name' => 'Maybe', 'phone' => '0501112200', 'pipeline' => Pipeline::Car]);

        Livewire::test(PipelineBoard::class)
            ->callAction('markLost', data: ['lost_reason' => ''], arguments: ['lead' => $lead->id])
            ->assertHasActionErrors(['lost_reason' => 'required']);
        $this->assertNotSame(LeadStatus::Lost, $lead->fresh()->status);

        $this->expectException(ValidationException::class);
        $lead->update(['stage' => Pipeline::LOST]);
    }

    public function test_lost_with_reason_works(): void
    {
        $lead = Lead::create(['name' => 'Gone', 'phone' => '0501112201', 'pipeline' => Pipeline::Car]);
        Livewire::test(ListLeads::class)->callTableAction('lost', $lead, data: ['lost_reason' => 'Price too high'])->assertHasNoTableActionErrors();
        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);
        $this->assertSame('Price too high', $lead->fresh()->lost_reason);
    }

    public function test_finance_cannot_move_or_edit_leads(): void
    {
        $lead = Lead::create(['name' => 'Locked', 'phone' => '0501112202', 'pipeline' => Pipeline::Car]);
        $this->actingAs($this->user('Finance'));

        Livewire::test(PipelineBoard::class)->call('moveLead', $lead->id, 'contacted');
        $this->assertSame('new', $lead->fresh()->stage);

        Livewire::test(EditLead::class, ['record' => $lead->id])->fillForm(['name' => 'Changed'])->call('save')->assertForbidden();
        $this->assertSame('Locked', $lead->fresh()->name);
    }

    public function test_driver_cannot_open_leads_or_customers(): void
    {
        $this->actingAs($this->user('Driver'));
        $this->get('/crm/leads')->assertForbidden();
        $this->get('/crm/customers')->assertForbidden();
        $this->get('/crm/pipeline')->assertForbidden();
    }
}
