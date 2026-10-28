<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Filament\Pages\BusinessSettings;
use App\Filament\Resources;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\User;
use App\Models\Yacht;
use App\Support\Options;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Tabs, widgets, roles, drivers and business settings (checklist 2, 4, 15, 16). */
class ScreensAndPermissionsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_every_tab_on_every_record_page_loads(): void
    {
        $this->actingAs($this->user('Super Admin'));
        $owners = [
            [Resources\LeadResource::class, Resources\LeadResource\Pages\EditLead::class, Lead::first()],
            [Resources\CustomerResource::class, Resources\CustomerResource\Pages\EditCustomer::class, Customer::first()],
            [Resources\CarResource::class, Resources\CarResource\Pages\EditCar::class, Car::first()],
            [Resources\YachtResource::class, Resources\YachtResource\Pages\EditYacht::class, Yacht::first()],
            [Resources\TripResource::class, Resources\TripResource\Pages\EditTrip::class, Trip::first()],
            [Resources\BookingResource::class, Resources\BookingResource\Pages\EditBooking::class, Booking::first()],
            [Resources\QuotationResource::class, Resources\QuotationResource\Pages\EditQuotation::class, Quotation::first()],
            [Resources\DriverResource::class, Resources\DriverResource\Pages\EditDriver::class, User::role('Driver')->first()],
        ];
        $count = 0;
        foreach ($owners as [$resource, $page, $record]) {
            foreach ($resource::getRelations() as $rm) {
                Livewire::test($rm, ['ownerRecord' => $record, 'pageClass' => $page])->assertOk();
                $count++;
            }
        }
        $this->assertGreaterThan(15, $count);
    }

    public function test_every_dashboard_and_accounts_widget_loads(): void
    {
        $this->actingAs($this->user('Super Admin'));
        foreach (array_merge(glob(app_path('Filament/Widgets/*.php')), glob(app_path('Filament/Pages/Accounts/Widgets/*.php'))) as $file) {
            $class = str_contains($file, 'Accounts')
                ? 'App\\Filament\\Pages\\Accounts\\Widgets\\'.basename($file, '.php')
                : 'App\\Filament\\Widgets\\'.basename($file, '.php');
            Livewire::test($class)->assertOk();
        }
    }

    public static function roles(): array
    {
        return array_map(fn ($r) => [$r], ['Super Admin', 'Admin', 'Manager', 'Sales', 'Agent', 'Operations', 'Finance', 'Staff']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_dashboard_opens_for_every_role(string $role): void
    {
        $this->actingAs($this->user($role));
        $response = $this->get('/crm')->assertOk()->assertSee('rp-console', false);
        // Money only for roles that may see it.
        \App\Support\Access::allows('manage-finance')
            ? $response->assertSee('received this month')
            : $response->assertDontSee('received this month');
    }

    public function test_sales_can_see_fleet_but_not_change_it(): void
    {
        $this->actingAs($this->user('Sales'));
        $car = Car::first();
        $this->get('/crm/cars')->assertOk();
        Livewire::test(Resources\CarResource\Pages\EditCar::class, ['record' => $car->id])
            ->fillForm(['daily_price' => 1])->call('save')->assertForbidden();
        $this->assertNotEquals('1.00', $car->fresh()->daily_price);
    }

    public function test_menu_uses_the_new_groups_and_shortcuts(): void
    {
        $this->actingAs($this->user('Super Admin'));
        $this->get('/crm')->assertOk()
            ->assertSeeInOrder(['Overview', 'Sales', 'Operations', 'Finance', 'Administration'])
            ->assertSee('Car readiness')
            ->assertSee('To collect')
            ->assertSee('Trips &amp; Packages', false)
            ->assertSee('/crm/yachts"', false); // charters back (28 Oct)
    }

    /** One role per test: switching users inside a test is caught by the session guard. */
    #[\PHPUnit\Framework\Attributes\DataProvider('adminListAccess')]
    public function test_documents_and_history_lists_follow_permissions(string $role, string $url, int $status): void
    {
        $this->actingAs($this->user($role));
        $this->get($url)->assertStatus($status);
    }

    public static function adminListAccess(): array
    {
        return [
            'sales opens documents' => ['Sales', '/crm/documents', 200],
            'finance cannot open documents' => ['Finance', '/crm/documents', 403],
            'manager opens history' => ['Manager', '/crm/history', 200],
            'sales cannot open history' => ['Sales', '/crm/history', 403],
        ];
    }

    public function test_a_document_is_approved_from_the_documents_list(): void
    {
        $this->actingAs($this->user('Operations'));
        $doc = \App\Models\CustomerDocument::create([
            'customer_id' => $this->customer()->id, 'type' => 'passport', 'path' => 'customers/1/p.pdf', 'original_name' => 'p.pdf',
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\DocumentResource\Pages\ListDocuments::class)
            ->assertCanSeeTableRecords([$doc])
            ->callTableAction('approve', $doc, ['expires_on' => today()->addYear()->toDateString()])
            ->assertHasNoTableActionErrors();

        $this->assertSame(\App\Enums\DocumentStatus::Approved, $doc->fresh()->status);
        $this->assertNotNull($doc->fresh()->reviewed_by);
    }

    public function test_sales_cannot_open_accounts_or_settings(): void
    {
        $this->actingAs($this->user('Sales'));
        $this->get('/crm/accounts')->assertForbidden();
    }

    public function test_finance_can_open_accounts(): void
    {
        $this->actingAs($this->user('Finance'));
        $this->get('/crm/accounts')->assertOk();
    }

    public function test_manager_cannot_open_business_settings(): void
    {
        $this->actingAs($this->user('Manager'));
        $this->get('/crm/business-settings')->assertForbidden();
    }

    public function test_driver_starts_on_my_jobs_and_cannot_open_the_bookings_list(): void
    {
        $driver = User::role('Driver')->first();
        $this->actingAs($driver);

        $this->get('/crm')->assertRedirect(\App\Filament\Pages\MyJobs::getUrl());
        $this->get('/crm/my-jobs')->assertOk();
        $this->get('/crm/bookings')->assertForbidden();
    }

    public function test_driver_cannot_open_someone_elses_booking(): void
    {
        $driver = User::role('Driver')->first();
        $other = Booking::whereNull('driver_id')->first();
        $this->actingAs($driver);
        $this->assertContains($this->get('/crm/bookings/'.$other->id.'/edit')->status(), [403, 404]);
    }

    public function test_drivers_page_creates_a_driver_with_usual_cars(): void
    {
        $this->actingAs($this->user('Manager'));
        $car = Car::first();
        Livewire::test(Resources\DriverResource\Pages\CreateDriver::class)
            ->fillForm(['name' => 'Bilal Driver', 'phone' => '0501110000', 'email' => 'bd@test.local', 'assignedCars' => [$car->id], 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();

        $d = User::firstWhere('email', 'bd@test.local');
        $this->assertTrue($d->hasRole('Driver'));
        $this->assertNotEmpty($d->password, 'a random password is set');
        $this->assertTrue($car->drivers()->whereKey($d->id)->exists());
        $this->assertArrayHasKey($d->id, Resources\BookingResource\BookingActions::driverOptions($car->id));
    }

    public function test_sales_cannot_add_drivers(): void
    {
        $this->actingAs($this->user('Sales'));
        $this->get('/crm/drivers/create')->assertForbidden();
    }

    public function test_admin_changes_settings_and_the_crm_follows(): void
    {
        $this->actingAs($this->user('Admin'));

        $page = Livewire::test(BusinessSettings::class);
        $state = $page->get('data');

        $state['list_lost_reasons'] = ['Too far', 'Went with friend'];
        $state['msg_greeting'] = 'Salam {first_name}, {company} here.';
        $state['late_grace_minutes'] = 60;
        $state['pending_payment_holds'] = false;
        $state['vat_enabled'] = true;
        $state['vat_rate'] = 5;
        $docs = array_values($state['document_types']);
        $docs[] = ['key' => null, 'label' => 'Hotel booking', 'on_link' => true];
        $state['document_types'] = $docs;
        $state['driver_job_gap_minutes'] = 45;
        $state['handover_min_photos'] = 3;
        $state['driver_steps'] = ['acknowledged', 'arrived'];
        $state['handover_checklist'] = ['Keys handed over', ' Salik tag checked '];
        $state['driver_required_documents'] = ['passport', 'licence'];
        $state['agent_own_records_only'] = false;
        $state['perm_manage_quotations'] = ['Admin', 'Manager', 'Sales', 'Finance'];
        $state['perm_manage_team'] = []; // Admin is kept anyway

        $page->set('data', $state)->call('save')->assertHasNoErrors();

        Setting::flushCache();
        $this->assertSame(['Too far', 'Went with friend'], Options::list('lost_reasons'));
        $this->assertSame('Salam Ahmed, RED PRIME RENTALS here.', Options::message('msg_greeting', ['name' => 'Ahmed Test']));
        $this->assertSame(60, \App\Support\Rules::lateGraceMinutes());
        $this->assertFalse(\App\Support\Rules::pendingPaymentHolds());
        $this->assertSame([BookingStatus::Confirmed, BookingStatus::InProgress], BookingStatus::blocking());
        $this->assertSame(5.0, \App\Support\Tax::defaultRate());
        $this->assertArrayHasKey('hotel_booking', Options::requestableDocumentTypes());
        $this->assertContains('Admin', \App\Support\Access::rolesFor('manage-team'));
        $this->assertSame(45, Setting::read('driver_job_gap_minutes'));
        $this->assertSame(3, Setting::read('handover_min_photos'));
        $this->assertSame(['acknowledged', 'arrived'], Setting::read('driver_steps'));
        $this->assertSame(['Keys handed over', 'Salik tag checked'], Setting::read('handover_checklist'));
        $this->assertSame(['passport', 'licence'], Setting::read('driver_required_documents'));
        $this->assertFalse(Setting::read('agent_own_records_only'));

        $this->actingAs($this->user('Finance'));
        $this->get('/crm/quotations/create')->assertOk();
        $this->assertTrue(\App\Models\AuditLog::where('event', 'settings')->exists());
    }
}
