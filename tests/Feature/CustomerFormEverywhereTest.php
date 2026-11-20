<?php

namespace Tests\Feature;

use App\Enums\Pipeline;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\QuotationResource\Pages\CreateQuotation;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 20 Nov (Bilal, item 9): the New Booking customer form everywhere a customer is added. */
class CustomerFormEverywhereTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->user('Admin'));
    }

    protected function details(array $extra = []): array
    {
        return array_merge([
            'type' => 'individual', 'name' => 'Omar Khan', 'phone' => '+971501112233', 'email' => 'omar@example.com',
            'residency' => 'resident', 'nationality' => 'Pakistan', 'id_number' => '784199012345671', 'id_expires_on' => '2028-06-05',
            'licence_number' => 'L-55', 'licence_expires_on' => '2030-01-01',
        ], $extra);
    }

    public function test_customers_add_customer_has_the_booking_form_and_keeps_its_own_extras(): void
    {
        $this->get(\App\Filament\Resources\CustomerResource::getUrl('create'))->assertOk()
            ->assertSee('ID and driving licence')->assertSee('Emirates ID number')->assertSee('Read the documents (OCR)')->assertSee('Preferences');

        Livewire::test(CreateCustomer::class)
            ->fillForm($this->details(['preferred_contact' => 'email', 'tags' => ['Corporate'], 'notes' => 'Prefers black cars']))
            ->call('create')->assertHasNoFormErrors();

        $c = Customer::sole();
        $this->assertSame('784-1990-1234567-1', $c->id_number, 'same clean-up as the booking form');
        $this->assertSame('L-55', $c->licence_number);
        $this->assertSame('email', $c->preferred_contact);
        $this->assertSame('Prefers black cars', $c->notes);

        // The same rules: a second customer with this phone is refused.
        Livewire::test(CreateCustomer::class)->fillForm($this->details(['email' => 'other@example.com']))->call('create')->assertHasFormErrors(['phone' => 'unique']);

        // The customer page shows and saves the same fields.
        Livewire::test(EditCustomer::class, ['record' => $c->getKey()])
            ->assertFormSet(['id_number' => '784-1990-1234567-1', 'licence_number' => 'L-55', 'residency' => 'resident'])
            ->fillForm(['passport_number' => 'AB123456'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('AB123456', $c->fresh()->passport_number);
        $this->assertSame('2030-01-01', $c->fresh()->licence_expires_on->toDateString(), 'dates kept as they were');
    }

    public function test_quotation_adds_a_customer_with_the_same_form(): void
    {
        Livewire::test(CreateQuotation::class)
            ->callFormComponentAction('customer_id', 'createOption', $this->details())
            ->assertHasNoFormComponentActionErrors();
        $c = Customer::sole();
        $this->assertSame('L-55', $c->licence_number);
        $this->assertSame('resident', $c->residency);
    }

    public function test_lead_customer_profile_uses_the_same_form_and_links_an_existing_customer_instead_of_a_duplicate(): void
    {
        $existing = $this->customer(['name' => 'Sara Ali', 'phone' => '+971509998877']);
        $lead = Lead::create(['name' => 'Sara', 'phone' => '0509998877', 'pipeline' => Pipeline::Car]);
        Livewire::test(ListLeads::class)
            ->mountTableAction('convert', $lead)
            ->assertTableActionDataSet(['existing_customer_id' => $existing->id, 'name' => 'Sara'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame($existing->id, $lead->fresh()->customer_id);
        $this->assertSame(1, Customer::count(), 'no duplicate');

        $new = Lead::create(['name' => 'Omar Khan', 'phone' => '+971501112233', 'pipeline' => Pipeline::Car]);
        Livewire::test(EditLead::class, ['record' => $new->getKey()])
            ->callAction('convert', $this->details(['existing_customer_id' => null]))
            ->assertHasNoActionErrors();
        $c = $new->fresh()->customer;
        $this->assertNotNull($c);
        $this->assertSame('784-1990-1234567-1', $c->id_number);
        $this->assertSame('L-55', $c->licence_number);
    }

    public function test_add_lead_uses_the_customer_form_or_picks_the_customer_on_file(): void
    {
        $source = \App\Models\LeadSource::firstOrCreate(['slug' => 'walk-in'], ['name' => 'Walk-in', 'is_active' => true]);
        $this->get(\App\Filament\Resources\LeadResource::getUrl('create'))->assertOk()->assertSee('ID and driving licence')->assertSee('New customer: fill in the form below');

        Livewire::test(\App\Filament\Resources\LeadResource\Pages\CreateLead::class)
            ->fillForm(['lead_source_id' => $source->id, 'customer' => $this->details()])
            ->call('create')->assertHasNoFormErrors();
        $lead = Lead::sole();
        $c = $lead->customer;
        $this->assertNotNull($c, 'customer profile made from the lead form');
        $this->assertSame('prospect', $c->status, 'a prospect until the lead is won');
        $this->assertSame('L-55', $c->licence_number);
        $this->assertSame('Omar Khan', $lead->name);
        $this->assertSame('+971501112233', $lead->phone);

        // Second lead for the same person: pick them, no duplicate.
        Livewire::test(\App\Filament\Resources\LeadResource\Pages\CreateLead::class, ['customer' => (string) $c->id])
            ->assertFormSet(['customer_id' => $c->id])
            ->fillForm(['lead_source_id' => $source->id])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Lead::where('customer_id', $c->id)->count());

        // A website lead without a profile is still edited with its own contact fields.
        $web = Lead::create(['name' => 'Insta user', 'email' => 'insta@example.com', 'pipeline' => Pipeline::Car, 'lead_source_id' => $source->id]);
        Livewire::test(EditLead::class, ['record' => $web->getKey()])
            ->assertSee('no customer profile yet')
            ->fillForm(['message' => 'Wants a G-Class'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Wants a G-Class', $web->fresh()->message);
        $this->assertNull($web->fresh()->customer_id);
    }

    public function test_create_quote_from_a_lead_fills_in_the_customer(): void
    {
        $existing = $this->customer(['name' => 'Sara Ali', 'phone' => '+971509998877']);
        $linked = Lead::create(['name' => 'Sara', 'phone' => '0501110000', 'pipeline' => Pipeline::Car, 'customer_id' => $existing->id]);
        Livewire::test(CreateQuotation::class, ['lead' => (string) $linked->id])->assertFormSet(['customer_id' => $existing->id]);

        $sameNumber = Lead::create(['name' => 'Sara again', 'phone' => '0509998877', 'pipeline' => Pipeline::Car]);
        Livewire::test(CreateQuotation::class, ['lead' => (string) $sameNumber->id])->assertFormSet(['customer_id' => $existing->id]);

        // No profile yet: the lead's details are shown and the quote is saved against a profile made from them.
        $car = $this->car();
        $new = Lead::create(['name' => 'Yusuf Karim', 'phone' => '0507778888', 'email' => 'yusuf@example.com', 'pipeline' => Pipeline::Car, 'service_type' => \App\Enums\ServiceType::Car,
            'interested_in_type' => 'car', 'interested_in_id' => $car->id, 'preferred_date' => '2026-12-10', 'end_date' => '2026-12-12']);
        Livewire::test(CreateQuotation::class, ['lead' => (string) $new->id])
            ->assertFormSet(['customer_id' => null, 'lead_id' => $new->id])
            ->assertSee('Yusuf Karim')->assertSee('A customer profile is made from these details')
            ->fillForm(['valid_until' => '2026-12-05'])
            ->call('create')->assertHasNoFormErrors();
        $q = \App\Models\Quotation::latest('id')->first();
        $this->assertNotNull($q->customer_id);
        $this->assertSame('Yusuf Karim', $q->customer->name);
        $this->assertSame($q->customer_id, $new->fresh()->customer_id, 'lead, customer and quotation stay connected');
        $this->assertSame($new->id, $q->lead_id);
    }
}
