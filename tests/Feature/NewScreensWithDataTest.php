<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\DamageReport;
use App\Models\Invoice;
use App\Models\PriceExtra;
use App\Models\PriceRule;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** The 28 Oct screens open with real rows in them, every tab included (a list with no rows hides cell errors). */
class NewScreensWithDataTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    public function test_lists_and_tabs_render_with_rows(): void
    {
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        $this->actingAs($this->user('Super Admin'));
        $car = $this->car(['is_published' => true]);
        $yacht = $this->yacht();
        PriceRule::create(['name' => 'Winter', 'service_type' => 'car', 'applies_to' => 'item', 'item_id' => $car->id, 'unit' => 'day', 'method' => 'percent', 'amount' => 10, 'starts_on' => '2026-11-01', 'ends_on' => '2026-12-31']);
        PriceExtra::create(['name' => 'Child seat', 'kind' => 'extra', 'unit' => 'per_day', 'amount' => 50]);
        $b = $this->booking($car, '2026-11-03 10:00', '2026-11-05 10:00');
        $b->recordPayment(300, 'card');
        Invoice::issueFor($b->fresh());
        $late = $this->booking($this->car(), '2026-10-30 10:00', '2026-11-01 10:00');
        $late->forceFill(['status' => BookingStatus::InProgress])->save();
        $this->booking($yacht, '2026-11-10 16:00', '2026-11-10 19:00', ['status' => BookingStatus::PendingPayment]);
        Transaction::create(['type' => 'expense', 'category' => 'fuel', 'amount' => 80, 'date' => today(), 'status' => 'paid', 'approval_status' => 'pending', 'vendor' => 'ENOC', 'booking_id' => $b->id]);
        Transaction::create(['type' => 'expense', 'category' => 'cleaning', 'amount' => 60, 'date' => today(), 'status' => 'paid', 'is_estimate' => true]);
        $rev = Transaction::create(['type' => 'expense', 'category' => 'rent', 'amount' => 999, 'date' => today(), 'status' => 'paid', 'description' => 'Typo']);
        $rev->reverse('Typed wrong');

        $urls = ['/crm', '/crm/invoices', '/crm/price-rules', '/crm/price-extras', '/crm/rate-card', '/crm/price-check',
            '/crm/profit-reports', '/crm/accounts', '/crm/bookings/'.$b->id.'/edit', '/crm/damage-reports'];
        foreach (['booking', 'car', 'yacht', 'agent', 'month', 'category'] as $by) {
            $urls[] = '/crm/profit-reports?from=2026-01-01&until=2026-12-31&by='.$by;
        }
        foreach (['all', 'month', 'income', 'expense', 'approval', 'estimates', 'outstanding'] as $tab) {
            $urls[] = '/crm/transactions?activeTab='.$tab;
        }
        foreach (['upcoming', 'today', 'out', 'late', 'waiting', 'enquiries', 'balance', 'completed', 'cancelled', 'all'] as $tab) {
            $urls[] = '/crm/bookings?activeTab='.$tab;
        }
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/crm/transactions?activeTab=approval')->assertSee('ENOC');
        $this->get('/crm/invoices')->assertSee('INV-00001')->assertSee($b->customer->name);
    }
}
