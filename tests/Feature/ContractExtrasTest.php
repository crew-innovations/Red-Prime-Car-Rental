<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\BookingResource\BookingActions;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\RentalContract;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Support\Accounts\MoneyAccounts;
use App\Support\BookingFlow;
use App\Support\Chat\WhatsAppNotify;
use App\Support\Contracts\Contracts;
use App\Support\Reports\RentalReport;
use App\Support\Suppliers\BookingProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 19 Nov 2026: suggestions 1–10 (Bilal: "do both 1-10"). */
class ContractExtrasTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 09:00:00');
        Storage::fake('private');
        $this->seedRoles();
        $this->actingAs($this->user('Admin'));
        $this->car = $this->car();
        Setting::write(['bank_accounts' => [
            ['key' => 'cash', 'name' => 'Office cash', 'kind' => 'cash'],
            ['key' => 'enbd', 'name' => 'ENBD current', 'kind' => 'bank', 'bank' => 'Emirates NBD'],
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_1_expired_car_papers_block_handover_and_service_km_is_flagged(): void
    {
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00');
        $this->assertSame([], BookingActions::handoverBlockers($b));

        $this->car->update(['insurance_expiry' => '2026-11-10', 'next_service_km' => 10500, 'mileage' => 10000]);
        $blockers = BookingActions::handoverBlockers($b->fresh());
        $this->assertStringContainsString('Insurance expired', implode(' ', $blockers));
        $this->assertSame('Service due in 500 km', $this->car->fresh()->documentAlerts()['Service km']);
        $this->assertTrue(Car::query()->documentsDueSoon()->whereKey($this->car->id)->exists());

        Setting::write(['block_expired_car_papers' => false]);
        $this->assertSame([], BookingActions::handoverBlockers($b->fresh()));

        $this->artisan('crm:fleet-reminders')->assertSuccessful();
    }

    public function test_2_money_goes_on_the_usual_account_and_the_summary_adds_up(): void
    {
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00');
        $cash = $b->recordPayment(500, 'cash');
        $card = $b->recordPayment(300, 'card');
        $this->assertSame('cash', $cash->bank_account);
        $this->assertSame('enbd', $card->bank_account);
        $b->recordPayment(200, PaymentMethod::Cheque->value)->forceFill(['cheque_number' => '000123', 'cheque_date' => '2026-11-25'])->save();

        $s = MoneyAccounts::summary(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertSame(500.0, $s['cash']['in']);
        $this->assertSame(500.0, $s['enbd']['in'], 'card 300 + cheque 200 into the bank');
        $r = new RentalReport(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59'));
        $this->assertSame('000123', $r->cheques()[0]->cheque_number);
    }

    public function test_3_deposit_statement_pdf(): void
    {
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00');
        BookingFlow::deliver($b->fresh(), ['delivery_km' => 100, 'delivery_fuel' => 8, 'deposit_received' => 2000, 'deposit_method' => 'cash', 'payment_amount' => (float) $b->total, 'payment_method' => 'card']);
        Carbon::setTestNow('2026-11-22 10:00:00');
        BookingFlow::receive($b->fresh(), ['return_km' => 300, 'return_fuel' => 8, 'charges' => ['cleaning' => 150], 'after_return' => 'ready']);
        \App\Support\Deposits\Settlement::settle($b->fresh(), ['refund_method' => 'cash']);
        $this->get(route('pdf.deposit', $b))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_4_reports_out_and_in_car_use_unpaid_and_vat_by_emirate(): void
    {
        Setting::write(['vat_enabled' => true, 'vat_rate' => 5]);
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00', ['tax_rate' => 5, 'tax_emirate' => 'sharjah']);
        BookingFlow::deliver($b->fresh(), ['delivery_km' => 100, 'delivery_fuel' => 8]);
        Invoice::issueFor($b->fresh());
        $b2 = $this->booking($this->car(), '2026-11-24 10:00', '2026-11-25 10:00', ['tax_rate' => 5]);
        Invoice::issueFor($b2->fresh());

        $r = new RentalReport(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59'));
        $this->assertCount(1, $r->movements());
        $this->assertSame('Out', $r->movements()[0]['kind']);
        $use = collect($r->carUse())->firstWhere(fn ($row) => $row['car']->id === $this->car->id);
        $this->assertSame(2.0, $use['days']);
        $this->assertCount(1, $r->unpaid(), 'out with the customer and nothing paid');
        $vat = $r->vatByEmirate();
        $this->assertSame(100.0, $vat['sharjah']['vat'], '2000 × 5%');
        $this->assertArrayHasKey('dubai', $vat, 'default emirate');

        foreach (array_keys(\App\Filament\Pages\RentalReports::VIEWS) as $tab) {
            $this->get(\App\Filament\Pages\RentalReports::getUrl(['tab' => $tab, 'from' => '2026-11-01', 'until' => '2026-11-30']))->assertOk();
        }
        $this->get(\App\Filament\Pages\MoneySettings::getUrl())->assertOk();
    }

    public function test_5_damage_report_with_areas_from_the_diagram(): void
    {
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00', ['customer_id' => $this->customer()->id]);
        $c = Contracts::make($b);
        \Livewire\Livewire::test(\App\Filament\Resources\RentalContractResource\Pages\ViewRentalContract::class, ['record' => $c->getKey()])
            ->callAction('damage', ['stage' => 'delivery', 'zones' => ['bonnet', 'front_left'], 'description' => 'Scratch'])
            ->assertHasNoActionErrors();
        $r = \App\Models\DamageReport::firstOrFail();
        $this->assertSame('Bonnet, Front left door / wing', $r->area);
        $this->assertStringContainsString('Areas: Bonnet, Front left door / wing', $r->description);
    }

    public function test_6_old_paper_contract_is_entered_as_a_closed_contract(): void
    {
        $c = Contracts::enterOld([
            'customer_id' => $this->customer()->id, 'car_id' => $this->car->id, 'paper_number' => 'P-0099',
            'start_at' => '2026-09-01 10:00', 'end_at' => '2026-09-05 10:00', 'km_out' => 9000, 'km_in' => 9800,
            'total' => 3500, 'paid' => 3000, 'method' => 'cash',
        ]);
        $this->assertSame('closed', $c->status);
        $this->assertSame('P-0099', $c->paper_number);
        $b = $c->booking;
        $this->assertSame(BookingStatus::Completed, $b->status);
        $this->assertSame(3500.0, (float) $b->total);
        $this->assertSame(500.0, (float) $b->balance);
        $this->assertSame(800, $c->closing['km_driven']);
        $this->assertNotSame('rented', $this->car->fresh()->status?->value, 'an old contract holds no car');
    }

    public function test_7_borrow_agreement_for_a_supplier_car(): void
    {
        $sup = Supplier::create(['name' => 'Al Noor Cars', 'type' => 'rental_company', 'status' => 'active']);
        $sup->rates()->create(['method' => 'per_day', 'amount' => 400]);
        $car = $this->car(['ownership' => 'partner', 'supplier_id' => $sup->id]);
        $b = $this->booking($car, '2026-11-20 10:00', '2026-11-22 10:00', ['supplier_id' => $sup->id]);
        $this->get(route('pdf.borrow', $b))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $other = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00');
        $this->get(route('pdf.borrow', $other))->assertNotFound();
    }

    public function test_8_marketplace_commission_counts_as_a_cost(): void
    {
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00', ['marketplace' => 'OneClickDrive', 'marketplace_ref' => 'OCD-1', 'marketplace_commission' => 250]);
        $p = BookingProfit::for($b->fresh());
        $this->assertSame(250.0, $p['other']);
    }

    public function test_9_whatsapp_template_is_sent_when_set_up(): void
    {
        $this->assertFalse(WhatsAppNotify::send('+971501234567', 'contract', ['A', 'B', 'C']), 'not set up: nothing sent');
        $i = Integration::for('meta_messages');
        $i->fill(['enabled' => true, 'mode' => 'live', 'status' => 'connected', 'config' => ['whatsapp_phone_number_id' => '123', 'wa_template_contract' => 'contract_sign', 'wa_template_language' => 'en']]);
        $i->putSecrets(['app_secret' => 's', 'verify_token' => 'v', 'whatsapp_token' => 'tok'], 'live');
        $i->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $this->assertTrue(WhatsAppNotify::send('0501234567', 'contract', ['John', 'RC-2026-00001', 'https://x']));
        Http::assertSent(fn ($r) => $r['to'] === '971501234567' && $r['template']['name'] === 'contract_sign' && $r['template']['components'][0]['parameters'][1]['text'] === 'RC-2026-00001');
    }

    public function test_10_signing_by_link_needs_the_emailed_code_when_switched_on(): void
    {
        Setting::write(['contract_sign_otp' => 'email']);
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00', ['customer_id' => $this->customer(['email' => 'john@example.com'])->id]);
        $c = Contracts::make($b);
        $token = basename(parse_url(Contracts::signLink($c), PHP_URL_PATH));
        $this->get('/sign/'.$token)->assertOk()->assertSee('Email me a code');
        $this->post('/sign/'.$token.'/code')->assertRedirect();
        $this->assertNotNull($c->fresh()->sign_otp_hash);

        $c->forceFill(['sign_otp_hash' => Hash::make('123456'), 'sign_otp_expires_at' => now()->addMinutes(10)])->save();
        $img = imagecreatetruecolor(100, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 1, 20, 90, 5, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagepng($img);
        $sig = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        $this->post('/sign/'.$token, ['name' => 'John', 'signature' => $sig, 'agree' => 1, 'code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/sign/'.$token, ['name' => 'John', 'signature' => $sig, 'agree' => 1, 'code' => '123456'])->assertOk();
        $this->assertSame('link + email code', $c->fresh()->signed_via);
    }
    public function test_11_new_contract_from_the_contracts_tab_makes_the_booking_and_the_contract(): void
    {
        $this->get(\App\Filament\Resources\RentalContractResource::getUrl('index'))->assertOk()->assertSee('New contract');
        $this->get(\App\Filament\Resources\RentalContractResource::getUrl('create'))->assertOk()->assertSee('Additional drivers');

        $customer = $this->customer();
        \Livewire\Livewire::test(\App\Filament\Resources\RentalContractResource\Pages\CreateRentalContract::class)
            ->assertFormSet(['service_type' => 'car'])
            ->fillForm([
                'customer_id' => $customer->id, 'bookable_id' => $this->car->id, 'drive_mode' => 'self_drive',
                'start_at' => '2026-11-20 10:00', 'end_at' => '2026-11-23 10:00', 'base_amount' => 3000, 'status' => 'confirmed',
                'contract' => ['type' => 'company', 'lpo_number' => 'LPO-77', 'company_contact' => 'Sara',
                    'drivers' => [['name' => 'Ali Khan', 'date_of_birth' => '1990-01-01', 'id_number' => '784-1990-1234567-1',
                        'licence_number' => 'L-1', 'licence_expires_on' => '2028-01-01', 'charge' => 100]]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $c = RentalContract::with('booking', 'drivers')->sole();
        $this->assertSame('company', $c->type);
        $this->assertSame('LPO-77', $c->lpo_number);
        $this->assertSame($customer->id, $c->customer_id);
        $this->assertSame($this->car->id, $c->booking->bookable_id);
        $this->assertSame('car', $c->booking->service_type->value);
        $this->assertTrue($c->drivers->contains('name', 'Ali Khan'));
        $this->assertSame(3100.0, (float) $c->booking->base_amount + (float) $c->booking->extras_amount, 'driver charge added to the booking');

        // Prefilled when opened from a customer or a car page.
        \Livewire\Livewire::test(\App\Filament\Resources\RentalContractResource\Pages\CreateRentalContract::class, ['customer' => (string) $customer->id, 'item' => (string) $this->car->id])
            ->assertFormSet(['customer_id' => $customer->id, 'bookable_id' => $this->car->id, 'service_type' => 'car']);
        $this->get(\App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $customer]))->assertOk()->assertSee('New contract');
        $this->get(\App\Filament\Resources\CarResource::getUrl('edit', ['record' => $this->car]))->assertOk()->assertSee('New contract');

        // Agents can use it too.
        $this->flushSession();
        $this->actingAs($this->user('Agent'));
        $this->get(\App\Filament\Resources\RentalContractResource::getUrl('create'))->assertOk();
    }
    public function test_12_customer_sees_documents_first_until_they_are_approved(): void
    {
        Setting::write([
            'document_types' => [['key' => 'passport', 'label' => 'Passport', 'on_link' => true], ['key' => 'licence', 'label' => 'Driving licence', 'on_link' => true]],
            'doc_requirements' => ['self_drive' => ['passport', 'licence']],
        ]);
        $customer = $this->customer();
        $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00', ['customer_id' => $customer->id]); // made by staff without documents
        $account = \App\Support\Accounts::customerAccount($customer);

        $this->flushSession();
        $this->actingAs($account);
        $this->get('/account')->assertRedirect(\App\Filament\Account\Pages\Documents::getUrl(panel: 'account'));
        $this->get('/account/bookings')->assertRedirect();
        $this->get('/account/documents')->assertOk()->assertSee('Before you can use your account')->assertSee('Driving licence');

        foreach (['passport', 'licence'] as $type) {
            \Livewire\Livewire::test(\App\Filament\Account\Pages\Documents::class)
                ->fillForm(['type' => $type, 'path' => \Illuminate\Http\UploadedFile::fake()->create($type.'.pdf', 20, 'application/pdf')])
                ->call('save')->assertHasNoFormErrors();
        }
        $this->get('/account')->assertRedirect(); // uploaded, not approved yet

        $docs = \App\Models\CustomerDocument::where('customer_id', $customer->id)->get();
        $docs->each(fn ($d) => $d->review(\App\Enums\DocumentStatus::Approved));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $account->id]);
        $this->get('/account')->assertOk();
        $this->get('/account/bookings')->assertOk();

        // Switched off: the old behaviour (open account).
        Setting::write(['account_documents_gate' => false, 'doc_requirements' => ['self_drive' => ['passport', 'licence', 'visa']]]);
        $this->get('/account')->assertOk();
    }
    public function test_13_fines_and_tolls_on_the_contract_customer_car_and_report(): void
    {
        $customer = $this->customer();
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-23 10:00', ['customer_id' => $customer->id]);
        BookingFlow::deliver($b->fresh(), ['delivery_km' => 100, 'delivery_fuel' => 8]);
        $c = Contracts::make($b->fresh());

        \Livewire\Livewire::test(\App\Filament\Resources\RentalContractResource\Pages\ViewRentalContract::class, ['record' => $c->getKey()])
            ->callAction('addFine', ['authority' => 'Dubai Police', 'ticket_no' => 'T-555', 'offence_at' => '2026-11-21 14:00', 'car_id' => $this->car->id, 'amount' => 600])
            ->assertHasNoActionErrors();
        $fine = \App\Models\TrafficFine::sole();
        $this->assertSame($b->id, $fine->booking_id, 'matched to this rental');
        $this->assertSame($customer->id, $fine->customer_id);
        $this->assertSame(\App\Enums\FineStatus::Matched, $fine->status);

        \App\Models\TollTrip::create(['toll_type' => 'salik', 'car_id' => $this->car->id, 'passed_at' => '2026-11-21 15:00', 'amount' => 4, 'status' => 'matched', 'booking_id' => $b->id, 'dedupe_key' => 'x1']);

        $this->get(\App\Filament\Resources\RentalContractResource::getUrl('view', ['record' => $c]))->assertOk()->assertSee('Fines and tolls')->assertSee('T-555');
        \Livewire\Livewire::test(\App\Filament\Resources\CustomerResource\RelationManagers\FinesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => \App\Filament\Resources\CustomerResource\Pages\EditCustomer::class])
            ->assertCanSeeTableRecords([$fine]);
        \Livewire\Livewire::test(\App\Filament\RelationManagers\TollsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => \App\Filament\Resources\CustomerResource\Pages\EditCustomer::class])
            ->assertCountTableRecords(1);
        \Livewire\Livewire::test(\App\Filament\RelationManagers\TollsRelationManager::class, ['ownerRecord' => $this->car, 'pageClass' => \App\Filament\Resources\CarResource\Pages\EditCar::class])
            ->assertCountTableRecords(1);

        $rows = \App\Support\Fines\RentalCharges::report('customer', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59'));
        $this->assertSame($customer->name, $rows[0]['name']);
        $this->assertSame(600.0, $rows[0]['fines_open']);
        $this->assertSame(4.0, $rows[0]['tolls_open']);
        $this->get(\App\Filament\Pages\RentalReports::getUrl(['tab' => 'fines', 'by' => 'customer', 'from' => '2026-11-01', 'until' => '2026-11-30']))->assertOk()->assertSee($customer->name);

        // The same ticket twice is refused.
        \Livewire\Livewire::test(\App\Filament\Resources\RentalContractResource\Pages\ViewRentalContract::class, ['record' => $c->getKey()])
            ->callAction('addFine', ['authority' => 'Dubai Police', 'ticket_no' => 'T-555', 'offence_at' => '2026-11-21 14:00', 'car_id' => $this->car->id, 'amount' => 600]);
        $this->assertSame(1, \App\Models\TrafficFine::count());
    }
    public function test_14_documents_and_receipts_are_read_on_this_computer_when_no_reader_is_set_up(): void
    {
        $customer = $this->customer(['id_number' => null, 'licence_number' => 'OLD-1']);
        $texts = json_encode([
            ['type' => 'emirates_id', 'text' => "Name: AHMED ALI KHAN\nID Number 784-1990-1234567-1\nDate of Birth 01/02/1990\nExpiry Date 05/06/2028"],
            ['type' => 'driving_licence', 'text' => "Driving License\nUnited Arab Emirates\nLicense No. 1234567\nIssue Date 10/03/2015\nExpiry Date 10/03/2030"],
        ]);
        \Livewire\Livewire::test(\App\Filament\Resources\CustomerResource\Pages\EditCustomer::class, ['record' => $customer->getKey()])
            ->mountAction('details')
            ->set('mountedActionsData.0.ocr_text', $texts)
            ->assertSet('mountedActionsData.0.id_number', '784-1990-1234567-1')
            ->assertSet('mountedActionsData.0.id_expires_on', '2028-06-05')
            ->assertSet('mountedActionsData.0.licence_expires_on', '2030-03-10')
            ->assertSet('mountedActionsData.0.licence_number', 'OLD-1') // typed values are never changed
            ->assertSet('mountedActionsData.0.ocr_text', null)
            ->assertNotified();

        // The button hands the files to the browser.
        Storage::disk('private')->put('customers/'.$customer->id.'/eid.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $page = new class
        {
            public array $sent = [];

            public function getId(): string
            {
                return 'abc';
            }

            public function dispatch($event, ...$params): void
            {
                $this->sent[] = [$event, $params];
            }
        };
        \App\Support\Ocr\LocalOcr::start($page, 'data.ocr_text', [['path' => Storage::disk('private')->path('customers/'.$customer->id.'/eid.png'), 'key' => 'emirates_id']]);
        $this->assertSame('rp-ocr-run', $page->sent[0][0]);
        $this->assertSame('data.ocr_text', $page->sent[0][1]['target']);
        $this->assertStringStartsWith('data:image/png;base64,', $page->sent[0][1]['files'][0]['data']);

        // Receipt on an expense.
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00');
        \Livewire\Livewire::test(\App\Filament\Resources\BookingResource\Pages\EditBooking::class, ['record' => $b->getKey()])
            ->mountAction('addExpense')
            ->set('mountedActionsData.0.receipt_ocr_text', json_encode([['type' => 'receipt', 'text' => "ENOC Service Station\nTRN: 100123456700003\nInvoice No: INV-8891\nDate: 18/11/2026\nVAT 5% 6.29\nTotal AED 132.00"]]))
            ->assertSet('mountedActionsData.0.amount', 132.0)
            ->assertSet('mountedActionsData.0.vendor', 'ENOC Service Station');
    }
    public function test_15_fines_follow_the_car_swap_on_a_contract(): void
    {
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-25 10:00', ['customer_id' => $this->customer()->id]);
        Carbon::setTestNow('2026-11-20 10:00:00');
        BookingFlow::deliver($b->fresh(), ['delivery_km' => 100, 'delivery_fuel' => 8]);
        $c = Contracts::make($b->fresh());
        $other = $this->car();
        Carbon::setTestNow('2026-11-22 12:00:00');
        Contracts::swap($c->fresh(), ['to_car_id' => $other->id, 'swapped_at' => '2026-11-22 12:00', 'reason' => 'Service', 'from_km' => 300, 'to_km' => 5000]);

        $use = fn ($car, $at) => \App\Support\Fines\VehicleUse::at($car, Carbon::parse($at));
        $this->assertSame($b->id, $use($this->car, '2026-11-21 09:00')['booking']?->id, 'first car, before the swap');
        $this->assertSame($b->id, $use($other, '2026-11-23 09:00')['booking']?->id, 'second car, after the swap');
        $this->assertNull($use($this->car, '2026-11-23 09:00')['booking'], 'first car after it came back');
        $this->assertNull($use($other, '2026-11-21 09:00')['booking'], 'second car before it joined');
    }
}
