<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Enums\Pipeline;
use App\Enums\QuotationStatus;
use App\Filament\Account\Pages;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\CustomerDocument;
use App\Models\DriverDocument;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Accounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 26 Oct brief: one shared system: login links, customer accounts, agents, private files. */
class AccountsAndAgentsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 08:00:00');
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------- login links ---------- */

    public function test_login_link_lets_a_driver_choose_a_password_once(): void
    {
        $this->actingAs($this->user('Manager'));
        $driver = $this->user('Driver', ['email' => 'imran@test.local']);
        $url = Accounts::inviteUrl($driver);
        auth()->logout();

        $this->get($url)->assertOk()->assertSee('imran@test.local');
        $this->post($url, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($url, ['password' => 'Desert2026', 'password_confirmation' => 'Desert2026'])->assertRedirect(url('/crm'));
        $this->assertAuthenticatedAs($driver);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Desert2026', $driver->fresh()->password));

        auth()->logout();
        $this->get($url)->assertStatus(410); // used: tied to the old password

        $fresh = Accounts::inviteUrl($driver->fresh());
        Carbon::setTestNow(now()->addHours(Accounts::linkHours() + 1));
        $this->get($fresh)->assertForbidden(); // expired signature
    }

    public function test_switched_off_accounts_cannot_use_a_link(): void
    {
        $driver = $this->user('Driver');
        $url = Accounts::inviteUrl($driver);
        $driver->update(['login_enabled' => false]);
        $this->get($url)->assertStatus(410);
    }

    /* ---------- customer accounts ---------- */

    public function test_customer_is_invited_and_sees_only_their_own_account(): void
    {
        $this->actingAs($this->user('Manager'));
        $ahmed = $this->customer(['name' => 'Ahmed Test', 'email' => 'ahmed@example.com']);
        $other = $this->customer(['name' => 'Other Person', 'email' => 'other@example.com']);
        $mine = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['customer_id' => $ahmed->id]);
        $theirs = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['customer_id' => $other->id]);
        $mine->recordPayment(500, 'card');

        Livewire::test(EditCustomer::class, ['record' => $ahmed->id])
            ->callAction('sendLoginLink', data: ['email' => false])
            ->assertHasNoActionErrors();
        $account = User::firstWhere('customer_id', $ahmed->id);
        $this->assertTrue($account->hasRole('Customer'));
        $this->assertSame('ahmed@example.com', $account->email);
        $this->assertNotNull($account->invited_at);

        $this->actingAs($account);
        $this->get('/crm')->assertForbidden();
        $this->get('/account')->assertOk()->assertSee('Hi Ahmed');
        $this->get('/account/bookings')->assertOk()->assertSee($mine->reference)->assertDontSee($theirs->reference);
        $this->get(Pages\BookingDetail::getUrl(['booking' => $mine->id], panel: 'account'))->assertOk()->assertSee('500.00');
        $this->get(Pages\BookingDetail::getUrl(['booking' => $theirs->id], panel: 'account'))->assertNotFound();
        $this->get('/account/payments')->assertOk()->assertSee($mine->reference)->assertDontSee($theirs->reference);

        Setting::write(['customer_sees_payments' => false]);
        $this->get('/account/payments')->assertForbidden();

        Setting::write(['customer_accounts_enabled' => false]);
        $this->get('/account')->assertForbidden();
    }

    public function test_customer_sees_sent_quotations_only_and_can_upload_documents(): void
    {
        $customer = $this->customer();
        $account = Accounts::customerAccount($customer);
        $car = $this->car();
        $base = ['customer_id' => $customer->id, 'service_type' => 'car', 'bookable_type' => 'car', 'bookable_id' => $car->id, 'base_amount' => 1000];
        $draft = Quotation::create($base + ['status' => QuotationStatus::Draft]);
        $sent = Quotation::create($base + ['status' => QuotationStatus::Sent]);
        Setting::write(['document_types' => [['key' => 'passport', 'label' => 'Passport', 'on_link' => true]]]);

        $this->actingAs($account);
        $this->get('/account/quotations')->assertOk()->assertSee($sent->number)->assertDontSee($draft->number);

        Livewire::test(Pages\Documents::class)
            ->fillForm(['type' => 'passport', 'path' => UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf')])
            ->call('save')
            ->assertHasNoFormErrors();

        $doc = CustomerDocument::firstWhere('customer_id', $customer->id);
        $this->assertSame(DocumentStatus::Received, $doc->status);
        $this->assertSame('account', $doc->uploaded_via);
        $this->get($doc->viewUrl())->assertOk();
    }

    public function test_upload_fields_cannot_point_at_someone_elses_file(): void
    {
        Storage::disk('private')->put('customers/999/theirs.pdf', 'x');
        $this->assertSame([], \App\Support\PrivateFiles::freshUploads(['customers/999/theirs.pdf'], 'customers/1'));
        $this->assertSame([], \App\Support\PrivateFiles::freshUploads(['tmp/../customers/999/theirs.pdf'], 'tmp'));

        Storage::disk('private')->put('tmp/old.jpg', 'x');
        CustomerDocument::create(['customer_id' => $this->customer()->id, 'type' => 'passport', 'path' => 'tmp/old.jpg']);
        $this->assertSame([], \App\Support\PrivateFiles::freshUploads(['tmp/old.jpg'], 'tmp'), 'already belongs to a document');

        Storage::disk('private')->put('tmp/new.jpg', 'x');
        $this->assertSame(['tmp/new.jpg'], \App\Support\PrivateFiles::freshUploads(['tmp/new.jpg'], 'tmp'));
    }

    /* ---------- private files ---------- */

    public function test_private_files_only_open_for_their_owner_or_staff(): void
    {
        Storage::disk('private')->put('customers/1/a.pdf', 'a');
        Storage::disk('private')->put('drivers/1/l.pdf', 'l');
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $cDoc = CustomerDocument::create(['customer_id' => $customer->id, 'type' => 'passport', 'path' => 'customers/1/a.pdf']);
        $driver = $this->user('Driver');
        $dDoc = DriverDocument::create(['user_id' => $driver->id, 'type' => 'licence', 'path' => 'drivers/1/l.pdf']);

        $this->actingAs(Accounts::customerAccount($customer));
        $this->get($cDoc->viewUrl())->assertOk();
        $this->get($dDoc->viewUrl())->assertForbidden();

        $this->actingAs(Accounts::customerAccount($otherCustomer));
        $this->get($cDoc->viewUrl())->assertForbidden();

        $this->actingAs($driver);
        $this->get($dDoc->viewUrl())->assertOk();
        $this->get($cDoc->viewUrl())->assertForbidden();

        $this->actingAs($this->user('Driver'));
        $this->get($dDoc->viewUrl())->assertForbidden();

        $this->actingAs($this->user('Operations'));
        $this->get($cDoc->viewUrl())->assertOk();
        $this->get($dDoc->viewUrl())->assertOk();
    }

    /* ---------- agents ---------- */

    public function test_agents_see_only_their_own_work_when_set(): void
    {
        $agent = $this->user('Agent');
        $colleague = $this->user('Sales');
        $myLead = Lead::create(['name' => 'Mine', 'phone' => '0501', 'pipeline' => Pipeline::Car, 'assigned_to' => $agent->id]);
        $otherLead = Lead::create(['name' => 'Not mine', 'phone' => '0502', 'pipeline' => Pipeline::Car, 'assigned_to' => $colleague->id]);
        $this->actingAs($colleague);
        $otherBooking = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-11 10:00');
        $this->actingAs($agent);
        $myBooking = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-11 10:00');

        Livewire::test(ListLeads::class)->assertCanSeeTableRecords([$myLead])->assertCanNotSeeTableRecords([$otherLead]);
        Livewire::test(ListBookings::class)->assertCanSeeTableRecords([$myBooking])->assertCanNotSeeTableRecords([$otherBooking]);
        $this->get('/crm/leads/'.$otherLead->id.'/edit')->assertNotFound();
        $this->get('/crm/business-settings')->assertForbidden();
        $this->get('/crm/accounts')->assertForbidden();

        Setting::write(['agent_own_records_only' => false]);
        Livewire::test(ListLeads::class)->assertCanSeeTableRecords([$myLead, $otherLead]);
    }

    public function test_team_list_never_shows_customer_logins(): void
    {
        $account = Accounts::customerAccount($this->customer());
        $this->actingAs($this->user('Admin'));
        Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)->assertCanNotSeeTableRecords([$account]);
    }
}
