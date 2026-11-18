<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\PriceExtra;
use App\Models\RentalContract;
use App\Models\Setting;
use App\Support\BookingFlow;
use App\Support\Contracts\ConfirmCheck;
use App\Support\Contracts\ContractInvoices;
use App\Support\Contracts\Contracts;
use App\Support\Ocr\DocumentReader;
use App\Support\Ocr\Mrz;
use App\Support\Pricing\PriceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 18 Nov 2026: meeting minutes (items 1–8) and rental contracts "same as on fleet". */
class RentalContractsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected bool $confirmNeedsDetails = true; // this file tests the rule itself

    protected Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00');
        Storage::fake('private');
        $this->seedRoles();
        $this->actingAs($this->user('Admin'));
        $this->car = $this->car(['delivery_fee' => 100]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function fullCustomer(array $attrs = []): Customer
    {
        return $this->customer($attrs + [
            'nationality' => 'United Kingdom', 'residency' => 'tourist', 'passport_number' => 'GB1234567',
            'licence_number' => 'UK998877', 'licence_expires_on' => '2030-01-01', 'licence_issued_on' => '2015-01-01', 'date_of_birth' => '1990-05-05',
        ]);
    }

    protected function rental(array $attrs = []): Booking
    {
        return $this->booking($this->car, '2026-11-20 10:00', '2026-11-23 10:00', $attrs + ['customer_id' => $this->fullCustomer()->id]);
    }

    protected function png(): string
    {
        $img = imagecreatetruecolor(120, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 5, 30, 110, 8, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /* ---------- Meeting item 2: delivery / collection charges ---------- */

    public function test_delivery_charge_is_off_until_switched_on_and_the_amount_can_be_changed(): void
    {
        $b = $this->rental(['delivery_method' => 'delivery', 'pickup_location' => 'Hotel']);
        $this->assertFalse(collect($b->extras)->contains('kind', 'delivery'), 'not added by itself');

        $b->update(['delivery_fee_on' => true]);
        $this->assertSame(100.0, (float) collect($b->fresh()->extras)->firstWhere('kind', 'delivery')['amount'], 'price list amount when switched on');

        $b->update(['delivery_fee_amount' => 150]);
        $line = collect($b->fresh()->extras)->firstWhere('kind', 'delivery');
        $this->assertSame(150.0, (float) $line['amount']);
        $this->assertNull($b->fresh()->price_approval, 'a set charge is not a discount');

        // Collection from the catalogue, switched on with its own amount.
        PriceExtra::create(['name' => 'Collection', 'kind' => 'collection', 'unit' => 'per_booking', 'amount' => 80, 'is_active' => true, 'service_types' => ['car']]);
        $b->update(['return_method' => 'collection', 'collection_fee_on' => true, 'collection_fee_amount' => 60]);
        $this->assertSame(60.0, (float) collect($b->fresh()->extras)->firstWhere('kind', 'collection')['amount']);

        // Switching delivery off for this booking removes the charge.
        $b->update(['delivery_fee_on' => false]);
        $this->assertFalse(collect($b->fresh()->extras)->contains('kind', 'delivery'));
    }

    public function test_quotations_and_old_bookings_keep_the_price_list_delivery_charge(): void
    {
        $r = PriceEngine::calculate(['service_type' => 'car', 'item' => $this->car, 'start_at' => '2026-11-20 10:00', 'end_at' => '2026-11-21 10:00', 'delivery_method' => 'delivery']);
        $this->assertTrue(collect($r['lines'])->contains('kind', 'delivery'), 'no switch given (quotation) = as before');

        Setting::write(['delivery_fee_default' => 'price_list']);
        $b = $this->booking($this->car, '2026-12-20 10:00', '2026-12-23 10:00', ['customer_id' => $this->fullCustomer()->id, 'delivery_method' => 'delivery', 'pickup_location' => 'Hotel']);
        $this->assertTrue(collect($b->extras)->contains('kind', 'delivery'), 'setting "price list" = as before 18 Nov');
    }

    /* ---------- Meeting item 4: deposit or no deposit ---------- */

    public function test_no_deposit_holds_nothing_and_can_carry_a_fee(): void
    {
        $b = $this->rental(['deposit_mode' => 'none', 'security_deposit' => 2000]);
        $this->assertSame(0.0, (float) $b->security_deposit);
        $this->assertFalse(collect($b->extras)->contains('key', 'no_deposit_fee'), 'no fee until one is set');

        $b->update(['no_deposit_fee' => 120]);
        $this->assertSame(120.0, (float) collect($b->fresh()->extras)->firstWhere('key', 'no_deposit_fee')['amount']);

        Setting::write(['no_deposit_fee_mode' => 'per_day', 'no_deposit_fee_amount' => 25]);
        $b2 = $this->booking($this->car(), '2026-11-20 10:00', '2026-11-23 10:00', ['customer_id' => $this->fullCustomer()->id, 'deposit_mode' => 'none']);
        $this->assertSame(75.0, (float) collect($b2->extras)->firstWhere('key', 'no_deposit_fee')['amount'], '3 days × 25');

        $b->update(['deposit_mode' => 'security']);
        $this->assertNull($b->fresh()->no_deposit_fee);
        $this->assertFalse(collect($b->fresh()->extras)->contains('key', 'no_deposit_fee'));
    }

    /* ---------- Meeting items 5 and 8: status and complete details ---------- */

    public function test_a_car_booking_is_confirmed_only_with_complete_details_and_documents(): void
    {
        $thin = $this->customer();
        try {
            $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00', ['customer_id' => $thin->id]);
            $this->fail('should be refused');
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first();
            $this->assertStringContainsString('Driving licence number', $msg);
            $this->assertStringContainsString('Emirates ID or passport', $msg);
        }

        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-22 10:00', ['customer_id' => $thin->id, 'status' => BookingStatus::PendingPayment]);
        $this->assertSame(BookingStatus::PendingPayment, $b->status, 'it can wait as Waiting for payment');

        $thin->update(['nationality' => 'India', 'residency' => 'resident', 'id_number' => '784-1990-1234567-1', 'licence_number' => 'D12345', 'licence_expires_on' => '2029-01-01']);
        // Documents per resident / GCC / tourist (Business settings › Documents).
        Setting::write(['doc_requirements' => ['resident' => ['emirates_id']]]);
        $this->assertSame(['Emirates ID (missing)'], ConfirmCheck::missing($b->fresh()));
        CustomerDocument::create(['customer_id' => $thin->id, 'type' => 'emirates_id', 'status' => DocumentStatus::Approved, 'path' => 'customers/1/eid.jpg', 'expires_on' => '2028-01-01']);

        $b->update(['status' => BookingStatus::Confirmed]);
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);
    }

    public function test_licence_expiring_before_the_return_and_blacklist_block_confirmation_and_admin_can_override(): void
    {
        $c = $this->fullCustomer(['licence_expires_on' => '2026-11-21']);
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-23 10:00', ['customer_id' => $c->id, 'status' => BookingStatus::PendingPayment]);
        $this->assertStringContainsString('expires before the return', implode(' ', ConfirmCheck::missing($b)));

        $c->update(['licence_expires_on' => '2030-01-01', 'blacklisted_at' => now(), 'blacklist_reason' => 'Unpaid fines']);
        $this->assertStringContainsString('blacklisted', implode(' ', ConfirmCheck::missing($b->fresh())));

        Booking::$confirmOverride = true;
        try {
            $b->update(['status' => BookingStatus::Confirmed]);
        } finally {
            Booking::$confirmOverride = false;
        }
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);
    }

    public function test_with_driver_bookings_do_not_need_the_customers_licence(): void
    {
        $c = $this->customer(['nationality' => 'India', 'residency' => 'tourist', 'passport_number' => 'P123']);
        $b = $this->booking($this->car, '2026-11-20 10:00', '2026-11-21 10:00', ['customer_id' => $c->id, 'drive_mode' => \App\Enums\DriveMode::WithDriver]);
        $this->assertSame(BookingStatus::Confirmed, $b->status);
    }

    public function test_only_admin_may_change_the_status_by_hand(): void
    {
        $agent = $this->user('Agent');
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser(auth()->user())->allows('change-booking-status'));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($agent)->allows('change-booking-status'));

        $this->actingAs($agent);
        $choices = array_map(fn ($s) => $s->value, \App\Filament\Resources\BookingResource::statusChoices(null));
        $this->assertSame(['inquiry', 'quotation', 'pending_payment'], $choices, 'an agent starts a booking as at most Waiting for payment');
        $b = $this->rental(['status' => BookingStatus::PendingPayment, 'assigned_to' => $agent->id]);
        $this->assertSame([BookingStatus::PendingPayment], \App\Filament\Resources\BookingResource::statusChoices($b));
    }

    /* ---------- Meeting item 1: rental contracts ---------- */

    public function test_contract_is_made_from_a_booking_with_number_main_driver_and_pdf_version(): void
    {
        $b = $this->rental(['km_limit_per_day' => 200, 'extra_km_rate' => 1.5, 'sales_source' => 'Instagram']);
        $c = Contracts::make($b);

        $this->assertMatchesRegularExpression('/^RC-2026-\d{5}$/', $c->number);
        $this->assertSame('draft', $c->status);
        $this->assertSame('individual', $c->type);
        $main = $c->mainDriver();
        $this->assertSame($b->customer->name, $main->name);
        $this->assertSame('UK998877', $main->licence_number);
        $this->assertSame(1, $c->version);
        $v = $c->latestVersion();
        Storage::disk('private')->assertExists($v->path);
        $this->assertStringStartsWith('%PDF', Storage::disk('private')->get($v->path));

        $this->assertSame($c->id, Contracts::make($b->fresh())->id, 'one contract per booking');
        $this->assertSame(200, $b->kmLimitPerDay());
    }

    public function test_additional_driver_charge_goes_on_the_booking_and_licence_is_checked(): void
    {
        $b = $this->rental();
        $c = Contracts::make($b);
        $before = (float) $b->fresh()->total;

        try {
            Contracts::addDriver($c, ['name' => 'Sara', 'date_of_birth' => '1995-01-01', 'id_number' => 'P1', 'licence_number' => 'L1', 'licence_expires_on' => '2026-11-21']);
            $this->fail('licence expires before the return');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('licence_expires_on', $e->errors());
        }

        Contracts::addDriver($c, ['name' => 'Sara', 'date_of_birth' => '1995-01-01', 'id_number' => 'P1', 'licence_number' => 'L1', 'licence_expires_on' => '2029-01-01', 'charge' => 90]);
        $this->assertSame(round($before + 90, 2), (float) $b->fresh()->total);
        $this->assertSame(2, $c->fresh()->drivers()->count());
        $this->assertSame(2, $c->fresh()->version, 'new PDF version');

        Contracts::removeDriver($c->fresh(), $c->drivers()->where('is_main', false)->first(), 'Not driving');
        $this->assertSame($before, (float) $b->fresh()->total);
    }

    public function test_customer_signs_through_the_link_once(): void
    {
        $c = Contracts::make($this->rental());
        $link = Contracts::signLink($c);
        $token = basename(parse_url($link, PHP_URL_PATH));

        $this->get('/sign/'.$token)->assertOk()->assertSee($c->number);
        auth()->logout();
        $this->post('/sign/'.$token, ['name' => 'John Smith', 'signature' => 'data:image/png;base64,'.base64_encode($this->png()), 'agree' => '1'])
            ->assertOk()->assertSee('is signed');

        $c->refresh();
        $this->assertNotNull($c->signed_at);
        $this->assertSame('link', $c->signed_via);
        $this->assertSame('John Smith', $c->signed_by_name);
        $this->assertNotNull($c->getFirstMedia('signature'));
        $this->assertSame(2, $c->version);
        $this->get('/sign/'.$token)->assertStatus(410);
    }

    public function test_extend_replace_car_and_close_with_km_of_both_cars(): void
    {
        $b = $this->rental(['km_limit_per_day' => 100]);
        $c = Contracts::make($b);
        BookingFlow::deliver($b->fresh(), ['delivery_km' => 10000, 'delivery_fuel' => 8]);
        $this->assertSame('open', $c->fresh()->status, 'open once the car is out');

        Contracts::extend($c->fresh(), '2026-11-24 10:00', 'Customer asked');
        $b->refresh();
        $this->assertSame('2026-11-24 10:00', $b->end_at->format('Y-m-d H:i'));
        $this->assertSame(4000.0, (float) $b->base_amount, '4 days × 1000');

        $other = $this->car(['name' => 'Second car']);
        Carbon::setTestNow('2026-11-21 12:00:00');
        Contracts::swap($c->fresh(), ['to_car_id' => $other->id, 'from_km_in' => 10150, 'from_fuel_in_8' => 6, 'to_km_out' => 5000, 'to_fuel_out_8' => 8, 'reason' => 'Breakdown']);
        $b->refresh();
        $this->assertSame($other->id, (int) $b->bookable_id);
        $this->assertSame(5000, (int) $b->delivery_km);
        $this->assertSame(10150, (int) $this->car->fresh()->mileage);

        try {
            Contracts::close($c->fresh());
            $this->fail('car still out');
        } catch (ValidationException) {
        }

        Carbon::setTestNow('2026-11-24 10:00:00');
        BookingFlow::receive($b->fresh(), ['return_km' => 5400, 'return_fuel' => 8, 'after_return' => 'ready']);
        Contracts::close($c->fresh(), 'All fine');
        $c->refresh();
        $this->assertSame('closed', $c->status);
        $this->assertSame(550, $c->closing['km_driven'], '150 km in the first car + 400 km in the second');
        $this->assertSame(400, $c->closing['km_included']);
        $this->assertSame(150, $c->closing['km_extra']);
        $this->assertSame(300.0, (float) $c->closing['extra_km_charge_suggested'], '150 × car rate 2');
        Storage::disk('private')->assertExists($c->latestVersion()->path);
    }

    public function test_cancelling_the_booking_cancels_the_contract(): void
    {
        $b = $this->rental();
        $c = Contracts::make($b);
        $b->update(['status' => BookingStatus::Cancelled, 'cancel_reason' => 'Changed plans']);
        $this->assertSame('cancelled', $c->fresh()->status);
    }

    public function test_monthly_contract_invoices_add_up_to_the_booking_and_never_twice(): void
    {
        $b = $this->booking($this->car, '2026-11-20 10:00', '2027-01-05 10:00', ['customer_id' => $this->fullCustomer()->id, 'rental_term' => 'long']);
        $c = Contracts::make($b);
        $this->assertSame('monthly', $c->type);
        $this->assertSame('2026-11-20', $c->fresh()->next_invoice_on->toDateString(), 'in advance: invoiced at the start');
        $c->forceFill(['status' => 'open'])->save();

        $first = ContractInvoices::issueNext($c->fresh());
        $this->assertSame('2026-11-20', $first->period_start->toDateString());
        $this->assertSame('2026-12-19', $first->period_end->toDateString());
        $this->assertSame('2026-12-20', $c->fresh()->next_invoice_on->toDateString());

        ContractInvoices::issueNext($c->fresh());
        $this->assertNull(ContractInvoices::nextPeriod($c->fresh()), 'the whole rental is invoiced');
        $this->assertNull(ContractInvoices::issueNext($c->fresh()));
        $net = round((float) $b->base_amount + (float) $b->extras_amount - (float) $b->discount, 2);
        $this->assertSame($net, round((float) Invoice::where('rental_contract_id', $c->id)->sum('subtotal'), 2));
    }

    public function test_daily_job_reminds_about_contracts_ending_soon(): void
    {
        $c = Contracts::make($this->rental());
        $c->forceFill(['status' => 'open'])->save();
        Carbon::setTestNow('2026-11-19 07:10:00');
        $done = ContractInvoices::runDaily();
        $this->assertSame(1, $done['reminders']);
    }

    public function test_contract_pdf_route_for_staff_and_for_the_customers_own_account(): void
    {
        $c = Contracts::make($this->rental());
        $this->get(route('pdf.contract', $c))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        auth()->logout();
        $this->get(route('pdf.contract', $c))->assertForbidden();
    }

    /* ---------- Meeting items 6 and 7: customer pop-up and OCR ---------- */

    public function test_customer_popup_saves_details_and_documents(): void
    {
        Storage::disk('private')->put('customers/new/eid.jpg', 'fake image');
        $c = \App\Filament\Concerns\CustomerDetails::create([
            'name' => 'Omar Ali', 'phone' => '+971 55 000 1111', 'type' => 'individual', 'residency' => 'resident', 'nationality' => 'Egypt',
            'id_number' => '784199012345671', 'id_expires_on' => '2028-02-02', 'upload_emirates_id' => ['customers/new/eid.jpg'], 'documents_checked' => true,
        ]);
        $this->assertSame('784-1990-1234567-1', $c->id_number);
        $doc = CustomerDocument::where('customer_id', $c->id)->first();
        $this->assertSame('emirates_id', $doc->type);
        $this->assertSame(DocumentStatus::Approved, $doc->status);
        $this->assertSame('784-1990-1234567-1', $doc->number);
        $this->assertSame('2028-02-02', $doc->expires_on->toDateString());

        // A path of a file that already belongs to someone is never taken.
        \App\Filament\Concerns\CustomerDetails::update($c, ['name' => 'Omar Ali', 'phone' => '+971 55 000 1111', 'upload_passport' => ['customers/new/eid.jpg']]);
        $this->assertSame(1, CustomerDocument::where('customer_id', $c->id)->count());
    }

    public function test_mrz_check_digits(): void
    {
        $m = Mrz::parse("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10");
        $this->assertTrue($m['valid']);
        $this->assertSame('L898902C3', $m['number']);
        $this->assertSame('1974-08-12', $m['date_of_birth']);
        $this->assertSame('Eriksson', $m['surname']);
        $bad = Mrz::parse("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C46UTO7408122F1204159ZE184226B<<<<<10");
        $this->assertFalse($bad['valid']);
    }

    public function test_document_reader_fills_fields_when_set_up_and_says_so_when_not(): void
    {
        try {
            DocumentReader::readCustomer([]);
            $this->fail('not set up');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not set up', $e->getMessage());
        }

        $i = Integration::for('document_reader');
        $i->fill(['enabled' => true, 'mode' => 'live', 'status' => 'connected', 'config' => ['provider' => 'anthropic']]);
        $i->putSecrets(['api_key' => 'test-key'], 'live');
        $i->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'name' => 'Omar Ali', 'nationality' => 'Egypt', 'id_number' => '784199012345671', 'id_expires_on' => '2028-02-02', 'licence_expires_on' => '02/03/2030', 'mrz' => null,
        ])]]])]);
        $file = tempnam(sys_get_temp_dir(), 'eid').'.png';
        file_put_contents($file, $this->png());
        $out = DocumentReader::readCustomer([['path' => $file, 'mime' => 'image/png', 'type' => 'Emirates ID']]);
        $this->assertSame('784-1990-1234567-1', $out['id_number']);
        $this->assertSame('Omar Ali', $out['name']);
        $this->assertArrayNotHasKey('licence_expires_on', $out, 'only clean dates go into date fields');
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'test-key') && str_contains(json_encode($r->data(), JSON_UNESCAPED_SLASHES), 'image/png'));
    }

    /* ---------- Screens open (no errors in the new fields and buttons) ---------- */

    public function test_new_screens_open_for_admin_and_agent(): void
    {
        $b = $this->rental(['delivery_method' => 'delivery', 'pickup_location' => 'Hotel', 'delivery_fee_on' => true]);
        $c = Contracts::make($b);
        Contracts::addDriver($c, ['name' => 'Sara', 'date_of_birth' => '1995-01-01', 'id_number' => 'P1', 'licence_number' => 'L1', 'licence_expires_on' => '2029-01-01']);

        foreach ([
            \App\Filament\Resources\BookingResource::getUrl('create'),
            \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $b]),
            \App\Filament\Resources\RentalContractResource::getUrl('index'),
            \App\Filament\Resources\RentalContractResource::getUrl('view', ['record' => $c]),
            \App\Filament\Pages\ContractSettings::getUrl(),
            \App\Filament\Pages\BusinessSettings::getUrl(),
            \App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $b->customer_id]),
            \App\Filament\Pages\SettingsHub::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $agent = $this->user('Agent');
        $b->update(['assigned_to' => $agent->id]);
        $b->customer->update(['assigned_to' => $agent->id]);
        $this->flushSession(); // another person: start a fresh session (Filament checks the session belongs to the user)
        $this->actingAs($agent);
        foreach ([\App\Filament\Resources\RentalContractResource::getUrl('index'), \App\Filament\Resources\RentalContractResource::getUrl('view', ['record' => $c]),
            \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $b])] as $url) {
            $r = $this->get($url);
            $this->assertSame(200, $r->status(), $url.' → '.$r->headers->get('Location'));
        }
        $this->get(\App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $b]))->assertSee('Only Admin changes the status');
        $this->get(\App\Filament\Pages\ContractSettings::getUrl())->assertForbidden();
    }

    public function test_contract_buttons_open_their_forms(): void
    {
        $b = $this->rental();
        $c = Contracts::make($b);
        BookingFlow::deliver($b->fresh(), ['delivery_km' => 100, 'delivery_fuel' => 8]);
        $page = \Livewire\Livewire::test(\App\Filament\Resources\RentalContractResource\Pages\ViewRentalContract::class, ['record' => $c->getKey()]);
        foreach (['signContract', 'signLink', 'addDriver', 'extend', 'swap', 'damage', 'attach', 'editDetails', 'closeContract', 'remakePdf'] as $name) {
            $page->mountAction($name)->assertHasNoActionErrors();
            $page->unmountAction();
        }
        $page->callAction('extend', ['end_at' => '2026-11-24 10:00', 'reason' => 'Asked'])->assertHasNoActionErrors();
        $this->assertSame('2026-11-24', $b->fresh()->end_at->toDateString());

        $edit = \Livewire\Livewire::test(\App\Filament\Resources\BookingResource\Pages\EditBooking::class, ['record' => $b->getKey()]);
        $edit->assertActionVisible('openContract');
        $edit->assertFormFieldExists('deposit_mode');
    }
}
