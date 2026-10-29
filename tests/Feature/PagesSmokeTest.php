<?php

namespace Tests\Feature;

use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\User;
use App\Models\Yacht;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Every CRM screen opens without errors, with the demo data loaded (checklist 15, 17). */
class PagesSmokeTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_every_page_opens_for_super_admin(): void
    {
        $this->actingAs($this->user('Super Admin'));

        $urls = ['/crm', '/crm/pipeline', '/crm/accounts', '/crm/business-settings', '/crm/profit-reports', '/crm/profit-reports?by=month', '/crm/profit-reports?by=category', '/crm/profit-reports?by=service', '/crm/rate-card', '/crm/price-check',
            '/crm/lead-reports', '/crm/lead-reports?by=agent', '/crm/lead-reports?by=campaign', '/crm/messages', '/crm/integrations', '/crm/calendar'];
        foreach (['leads', 'quotations', 'bookings', 'customers', 'cars', 'yachts', 'trips', 'packages', 'drivers', 'transactions', 'users', 'categories', 'lead-sources', 'documents', 'history', 'damage-reports', 'payment-requests', 'lead-forms', 'transfer-services', 'vehicle-categories', 'transfer-rates', 'transfer-fixed-routes'] as $r) {
            $urls[] = "/crm/{$r}";
        }
        foreach (['leads', 'quotations', 'bookings', 'customers', 'cars', 'trips', 'packages', 'drivers', 'transactions', 'users'] as $r) {
            $urls[] = "/crm/{$r}/create";
        }
        foreach (\App\Models\Package::all() as $p) {
            $urls[] = "/crm/packages/{$p->id}/edit";
            $urls[] = "/crm/preview/package/{$p->id}";
        }
        $urls[] = '/crm/preview/yacht-trip/'.Trip::first()->id;
        $urls[] = '/crm/leads/'.Lead::first()->id.'/edit';
        $urls[] = '/crm/customers/'.Customer::first()->id.'/edit';
        $urls[] = '/crm/cars/'.Car::first()->id.'/edit';
        $urls[] = '/crm/yachts/'.Yacht::first()->id.'/edit';
        $urls[] = '/crm/trips/'.Trip::first()->id.'/edit';
        $urls[] = '/crm/transactions/'.Transaction::first()->id.'/edit';
        $urls[] = '/crm/users/'.User::first()->id.'/edit';
        $urls[] = '/crm/drivers/'.User::role('Driver')->first()->id.'/edit';
        foreach (Booking::all() as $b) {
            $urls[] = "/crm/bookings/{$b->id}/edit";
        }
        foreach (Quotation::all() as $q) {
            $urls[] = "/crm/quotations/{$q->id}/edit";
        }
        $urls[] = '/crm/quotations/create?lead='.Lead::first()->id;
        $urls[] = '/crm/bookings/create?lead='.Lead::first()->id;

        foreach ($urls as $url) {
            $res = $this->get($url);
            $e = $res->exception;
            while ($e?->getPrevious()) {
                $e = $e->getPrevious();
            }
            $this->assertSame(200, $res->status(), "{$url} returned {$res->status()}".($e ? ': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine() : ''));
        }
    }

    public function test_driver_and_customer_screens_open(): void
    {
        $driver = User::role('Driver')->first();
        $booking = Booking::where('service_type', 'car')->first();
        $booking->update(['driver_id' => $driver->id]);
        $job = $booking->jobs()->first();
        $this->assertNotNull($job);

        $this->actingAs($driver);
        foreach (['/crm/my-jobs', '/crm/my-jobs?tab=upcoming', '/crm/my-jobs?tab=done', '/crm/my-profile', '/crm/messages', \App\Filament\Pages\DriverJob::getUrl(['job' => $job->id])] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_customer_account_screens_open(): void
    {
        $customer = Booking::whereNotNull('customer_id')->where('status', '!=', 'inquiry')->first()->customer;
        $account = \App\Support\Accounts::customerAccount($customer, 'smoke@example.com');
        $this->actingAs($account);
        foreach (['/account', '/account/bookings', '/account/quotations', '/account/documents', '/account/payments', '/account/profile', '/account/messages'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (\App\Support\CustomerArea::bookings()->get() as $b) {
            $this->get(\App\Filament\Account\Pages\BookingDetail::getUrl(['booking' => $b->id], panel: 'account'))->assertOk();
        }
    }

    public function test_yachts_can_be_added_again_and_old_ones_open(): void
    {
        // Charters were stopped on 21 Oct and are back from 28 Oct (Bilal).
        $this->actingAs($this->user('Super Admin'));
        $this->get('/crm/yachts/create')->assertOk();
        $this->get('/crm/yachts/'.Yacht::first()->id.'/edit')->assertOk();
    }

    public function test_list_tabs_open(): void
    {
        $this->actingAs($this->user('Super Admin'));
        foreach ([
            'cars' => ['available', 'attention', 'rented', 'maintenance', 'documents', 'hidden'],
            'yachts' => ['available', 'attention', 'booked', 'maintenance'],
            'customers' => ['vip', 'documents', 'follow_up'],
            'quotations' => ['open', 'accepted', 'closed', 'all'],
            'documents' => ['all', 'review', 'expired', 'replace'],
            'transactions' => ['outstanding'],
        ] as $r => $tabs) {
            foreach ($tabs as $tab) {
                $this->get("/crm/{$r}?activeTab={$tab}")->assertOk();
            }
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/crm/bookings')->assertRedirect();
    }

    public function test_landing_page_shows_the_right_login_buttons(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Team login')->assertSee(url('/crm/login'), false)
            ->assertSee('Customer login')->assertSee(url('/account/login'), false)
            ->assertSee('noindex', false);

        \App\Models\Setting::write(['customer_accounts_enabled' => false]);
        $this->get('/')->assertOk()->assertSee('Team login')->assertDontSee('Customer login');

        $this->actingAs($this->user('Super Admin'));
        $this->get('/')->assertOk()->assertSee('Open the CRM')->assertDontSee('Team login');

        $customer = Booking::whereNotNull('customer_id')->first()->customer;
        $this->actingAs(\App\Support\Accounts::customerAccount($customer, 'landing@example.com'));
        $this->get('/')->assertOk()->assertSee('Open my account');
    }

    public function test_public_quotation_page_only_after_sending(): void
    {
        $q = Quotation::where('status', QuotationStatus::Draft)->first();
        $this->get('/quote/'.$q->public_token)->assertNotFound();

        $sent = Quotation::where('status', QuotationStatus::Sent)->first();
        $this->get('/quote/'.$sent->public_token)->assertOk()->assertSee($sent->number);
        $this->assertNotNull($sent->fresh()->viewed_at, 'opening the link records when the customer viewed it');
        $this->get('/quote/not-a-real-token')->assertNotFound();
    }
}
