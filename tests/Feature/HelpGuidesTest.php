<?php

namespace Tests\Feature;

use App\Filament\Pages\HelpCentre;
use App\Filament\Resources\LeadResource;
use App\Models\User;
use App\Support\Help;
use App\Support\HelpGuides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 20 Nov (Bilal, item 12): a page-specific help guide on every CRM page and in the customer account. */
class HelpGuidesTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_every_crm_page_has_its_own_complete_guide(): void
    {
        $guides = HelpGuides::all();
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! Str::startsWith($name, 'filament.admin.') || Str::startsWith($name, 'filament.admin.auth.')) {
                continue;
            }
            $key = Str::after($name, 'filament.admin.');
            $id = HelpGuides::forRoute($key);
            if (! $id || ! isset($guides[$id])) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, 'pages without a help guide');

        foreach ($guides + ['portal' => HelpGuides::portal()] as $id => $guide) {
            foreach (['title', 'for', 'who'] as $field) {
                $this->assertNotEmpty($guide[$field] ?? null, "$id: $field");
            }
            $this->assertNotEmpty($guide['steps'], "$id: steps");
            foreach ($guide['steps'] as $title => $steps) {
                $this->assertNotEmpty($steps, "$id: $title");
            }
        }
        foreach (HelpGuides::FOCUS as $route => $focus) {
            $this->assertArrayHasKey($focus, $guides[HelpGuides::forRoute($route)]['steps'], $route);
        }
        foreach (HelpGuides::PORTAL_FOCUS as $route => $focus) {
            $this->assertArrayHasKey($focus, HelpGuides::portal()['steps'], $route);
        }
    }

    public function test_the_help_button_opens_the_guide_of_the_page(): void
    {
        $this->actingAs($this->user('Admin'));

        $this->get(LeadResource::getUrl('index'))->assertOk()
            ->assertSee('rp-help-btn', false)->assertSee('Leads')
            ->assertSee('Give a lead to an agent (Admin)')->assertSee('Delete a lead')->assertSee('Search, filter and work the list')
            ->assertSee('All help guides');

        // On "New lead" the "how to" for adding a lead comes first and open.
        $guide = Help::guide('resources.leads.create', false);
        $this->assertSame('Add a new lead', array_key_first($guide['steps']));
        $this->assertSame('Add a new lead', $guide['focus']);

        $this->get(\App\Filament\Resources\HistoryResource::getUrl('index'))->assertOk()->assertSee('rp-help-btn', false)->assertSee('See what an agent did');
        $this->get(\App\Filament\Pages\Dashboard::getUrl())->assertOk()->assertSee('Work through today');
    }

    public function test_help_centre_lists_every_guide(): void
    {
        $this->actingAs($this->user('Admin'));
        $this->get(HelpCentre::getUrl())->assertOk()
            ->assertSee('Help centre')->assertSee('Traffic fines, Salik &amp; tolls', false)->assertSee('Audit log')
            ->assertSee('Customer account (what customers see)')->assertSee('Rental contracts');

    }

    public function test_drivers_get_their_own_help(): void
    {
        $this->actingAs($this->user('Driver'));
        $this->get(HelpCentre::getUrl())->assertOk()->assertSee('Driver screens')->assertDontSee('Audit log');
        $this->get(\App\Filament\Pages\MyProfile::getUrl())->assertOk()->assertSee('rp-help-btn', false)->assertSee('Send a document');
    }

    public function test_customer_account_pages_have_help(): void
    {
        $customer = $this->customer(['name' => 'Ahmed Test', 'email' => 'ahmed@example.com']);
        $account = User::factory()->create(['email' => 'ahmed@example.com', 'customer_id' => $customer->id]);
        $account->assignRole('Customer');
        $this->actingAs($account);

        $this->get('/account/documents')->assertOk()->assertSee('rp-help-btn', false)->assertSee('Your account')
            ->assertSee('Complete your account')->assertDontSee('All help guides');
    }
}
