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

    public function test_sales_can_see_fleet_but_not_change_it(): void
    {
        $this->actingAs($this->user('Sales'));
        $car = Car::first();
        $this->get('/crm/cars')->assertOk();
        Livewire::test(Resources\CarResource\Pages\EditCar::class, ['record' => $car->id])
            ->fillForm(['daily_price' => 1])->call('save')->assertForbidden();
        $this->assertNotEquals('1.00', $car->fresh()->daily_price);
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

    public function test_driver_sees_only_their_own_bookings(): void
    {
        $driver = User::role('Driver')->first();
        $mine = Booking::where('driver_id', $driver->id)->first();
        $this->assertNotNull($mine);
        $this->actingAs($driver);

        Livewire::test(Resources\BookingResource\Pages\ListBookings::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords(Booking::where('driver_id', '!=', $driver->id)->orWhereNull('driver_id')->get());
    }

    public function test_driver_cannot_open_someone_elses_booking(): void
    {
        $driver = User::role('Driver')->first();
        $other = Booking::whereNull('driver_id')->first();
        $this->actingAs($driver);
        $this->get('/crm/bookings/'.$other->id.'/edit')->assertNotFound();
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

        $this->actingAs($this->user('Finance'));
        $this->get('/crm/quotations/create')->assertOk();
        $this->assertTrue(\App\Models\AuditLog::where('event', 'settings')->exists());
    }
}
