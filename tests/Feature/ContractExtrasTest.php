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
}
