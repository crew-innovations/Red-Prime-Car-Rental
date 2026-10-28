<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Support\Exports\TableExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 28 Oct-2, Phase 7: Excel / CSV exports follow the list, permissions and the log. */
class ExportsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_export_follows_the_tab_and_search_and_puts_the_reference_first(): void
    {
        $this->actingAs($this->user('Manager'));
        $a = $this->booking($this->car(['name' => 'Bentley']), '2026-11-10 10:00', '2026-11-12 10:00');
        $b = $this->booking($this->car(['name' => 'Ferrari']), '2026-11-11 10:00', '2026-11-12 10:00', ['status' => BookingStatus::PendingPayment]);
        $this->booking($this->car(), '2026-10-01 10:00', '2026-10-02 10:00', ['status' => BookingStatus::Cancelled]);

        $page = Livewire::test(ListBookings::class, ['activeTab' => 'waiting'])->instance();
        $rows = TableExport::toArray($page);
        $this->assertSame('ID', $rows[0][0]);
        $this->assertCount(2, $rows, 'header + the one booking in the tab');
        $this->assertSame($b->reference, $rows[1][0]);

        $page = Livewire::test(ListBookings::class, ['activeTab' => 'all'])->set('tableSearch', $a->reference)->instance();
        $rows = TableExport::toArray($page);
        $this->assertSame([$a->reference], array_column(array_slice($rows, 1), 0));
    }

    public function test_formula_text_is_made_safe_and_numbers_stay_numbers(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", TableExport::safe('=HYPERLINK("x")'));
        $this->assertSame('+971 50', TableExport::cell('+971 50'), 'phone numbers stay text');
        $this->assertSame("'+971 50", TableExport::safe('+971 50'), 'and are made safe in CSV');
        $this->assertSame("'-1+2", TableExport::safe('-1+2'));
        $this->assertSame(1250.5, TableExport::cell('1250.50'));
        $this->assertSame(-300, TableExport::cell(-300));
        $this->assertSame('Yes', TableExport::cell(true));
        $this->assertSame('Confirmed', TableExport::cell(BookingStatus::Confirmed));
        $this->assertSame('2026-11-02 09:00', TableExport::cell(now()));

        $this->actingAs($this->user('Manager'));
        Lead::create(['name' => '=cmd|calc', 'phone' => '0501234567', 'pipeline' => 'car', 'service_type' => 'car']);
        $rows = TableExport::toArray(Livewire::test(ListLeads::class, ['activeTab' => 'all'])->instance());
        $this->assertContains('=cmd|calc', $rows[1]);
        $this->assertSame("'=cmd|calc", TableExport::safe('=cmd|calc'));
    }

    public function test_csv_and_excel_downloads_are_logged(): void
    {
        $this->actingAs($this->user('Finance'));
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00');
        $b->recordPayment(500, 'card');

        Livewire::test(ListTransactions::class)->callAction('export', ['format' => 'csv'])->assertFileDownloaded();
        Livewire::test(ListTransactions::class)->callAction('export', ['format' => 'xlsx'])->assertFileDownloaded();

        $logs = AuditLog::where('event', 'export')->get();
        $this->assertCount(2, $logs);
        $this->assertStringContainsString('as CSV', $logs[0]->note);
        $this->assertStringContainsString('as XLSX', $logs[1]->note);
        $this->assertGreaterThanOrEqual(1, $logs[0]->new_values['rows']);
    }

    public function test_only_allowed_roles_see_export_and_agents_only_export_their_own(): void
    {
        $this->actingAs($this->user('Sales'));
        Livewire::test(ListCustomers::class)->assertActionHidden('export');
    }

    public function test_agent_given_export_rights_still_only_exports_their_own_records(): void
    {
        $this->settings(['role_abilities' => array_merge(\App\Support\Access::ABILITIES, ['export-data' => ['Admin', 'Manager', 'Finance', 'Agent']])]);
        $agent = $this->user('Agent');
        $other = $this->user('Agent');
        $this->actingAs($agent);
        Lead::create(['name' => 'Mine', 'phone' => '0501111111', 'pipeline' => 'car', 'service_type' => 'car', 'assigned_to' => $agent->id]);
        Lead::create(['name' => 'Theirs', 'phone' => '0502222222', 'pipeline' => 'car', 'service_type' => 'car', 'assigned_to' => $other->id]);

        $page = Livewire::test(ListLeads::class, ['activeTab' => 'all'])->assertActionVisible('export')->instance();
        $names = collect(TableExport::toArray($page))->flatten()->all();
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_excel_file_keeps_formula_text_as_text(): void
    {
        $this->actingAs($this->user('Manager'));
        Lead::create(['name' => '=HYPERLINK("http://x","y")', 'phone' => '+971501234567', 'pipeline' => 'car', 'service_type' => 'car']);
        $response = TableExport::download(Livewire::test(ListLeads::class, ['activeTab' => 'all'])->instance(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $sheet, 'no formula cells');
        $this->assertStringContainsString('=HYPERLINK', html_entity_decode($sheet));
        $this->assertStringContainsString('+971501234567', $sheet);
    }
}
