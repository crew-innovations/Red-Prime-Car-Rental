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

        $urls = ['/crm', '/crm/pipeline', '/crm/accounts', '/crm/business-settings'];
        foreach (['leads', 'quotations', 'bookings', 'customers', 'cars', 'yachts', 'trips', 'packages', 'drivers', 'transactions', 'users', 'categories', 'lead-sources', 'documents', 'history'] as $r) {
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

    public function test_new_yachts_cannot_be_added_but_old_ones_still_open(): void
    {
        // RED PRIME does not own yachts (Oct 2026): no new yacht records, old ones stay viewable.
        $this->actingAs($this->user('Super Admin'));
        $this->get('/crm/yachts/create')->assertForbidden();
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
