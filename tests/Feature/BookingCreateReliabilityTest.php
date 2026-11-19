<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Models\Booking;
use App\Models\Car;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 19 Nov (Bilal, item 7): booking creation glitches and customer profile rules. */
class BookingCreateReliabilityTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected bool $confirmNeedsDetails = true; // the 18 Nov rule is on, as on the live server

    protected Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 09:00:00');
        $this->seedRoles();
        $this->car = $this->car();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function form(array $extra = []): array
    {
        return array_merge(['service_type' => 'car', 'bookable_id' => $this->car->id, 'drive_mode' => 'self_drive',
            'start_at' => '2026-11-25 10:00', 'end_at' => '2026-11-27 10:00', 'base_amount' => 2000], $extra);
    }

    public function test_admin_with_an_incomplete_customer_is_not_blocked_the_booking_is_saved_as_waiting_for_payment(): void
    {
        $this->actingAs($this->user('Admin'));
        $customer = $this->customer(['id_number' => null, 'licence_number' => null]);

        Livewire::test(CreateBooking::class)
            ->fillForm($this->form(['customer_id' => $customer->id, 'status' => 'confirmed']))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $b = Booking::sole();
        $this->assertSame(BookingStatus::PendingPayment, $b->status);
        $this->assertNotNull($b->reference);
    }

    public function test_agent_can_make_a_booking_for_an_incomplete_customer_and_sees_it_in_the_list(): void
    {
        $agent = $this->user('Agent');
        $this->actingAs($agent);
        $customer = $this->customer(['id_number' => null]);

        Livewire::test(CreateBooking::class)
            ->fillForm($this->form(['customer_id' => $customer->id]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $b = Booking::sole();
        $this->assertSame($agent->id, $b->assigned_to);
        Livewire::test(\App\Filament\Resources\BookingResource\Pages\ListBookings::class, ['activeTab' => 'new'])
            ->assertCanSeeTableRecords([$b]);
    }

    public function test_a_second_click_never_makes_a_second_booking(): void
    {
        $this->actingAs($this->user('Admin'));
        $customer = $this->customer();
        $page = Livewire::test(CreateBooking::class)->fillForm($this->form(['customer_id' => $customer->id]));
        $token = $page->get('saveToken');

        // While the first click is still saving, the second one waits.
        $lock = Cache::lock('booking-create:'.auth()->id().':'.$token, 60);
        $lock->get();
        $page->call('create')->assertNotified('Saving… please wait');
        $this->assertSame(0, Booking::count());
        $lock->release();

        $page->call('create')->assertHasNoFormErrors();
        $page->call('create'); // clicked again after it was saved
        $this->assertSame(1, Booking::count());
    }

    public function test_a_problem_found_while_saving_is_shown_and_nothing_is_saved(): void
    {
        $this->actingAs($this->user('Admin'));
        $this->booking($this->car, '2026-11-24 10:00', '2026-11-28 10:00', ['status' => BookingStatus::Confirmed]);

        Livewire::test(CreateBooking::class)
            ->fillForm($this->form(['customer_id' => $this->customer()->id]))
            ->call('create')
            ->assertHasFormErrors(['end_at'])
            ->assertNotified('The booking was not saved')
            ->assertDispatched('form-validation-error');

        $this->assertSame(1, Booking::count());
    }

    public function test_agent_passing_the_booking_to_another_agent_goes_back_to_the_list_not_a_missing_page(): void
    {
        $agent = $this->user('Agent');
        $other = $this->user('Agent');
        $this->actingAs($agent);

        Livewire::test(CreateBooking::class)
            ->fillForm($this->form(['customer_id' => $this->customer()->id, 'assigned_to' => $other->id]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\BookingResource::getUrl('index', ['activeTab' => 'all']));
        $this->assertSame($other->id, Booking::sole()->assigned_to);
    }

    public function test_customer_completes_missing_details_in_the_portal_before_it_opens(): void
    {
        $this->actingAs($this->user('Admin'));
        $customer = $this->customer(['nationality' => null, 'residency' => null, 'id_number' => null, 'passport_number' => null, 'licence_number' => null, 'licence_expires_on' => null]);
        $this->booking($this->car, '2026-11-25 10:00', '2026-11-27 10:00', ['customer_id' => $customer->id, 'drive_mode' => \App\Enums\DriveMode::SelfDrive]);
        $account = \App\Support\Accounts::customerAccount($customer);

        $this->flushSession();
        $this->actingAs($account);
        $this->get('/account')->assertRedirect(\App\Filament\Account\Pages\Documents::getUrl(panel: 'account'));
        $this->get('/account/documents')->assertOk()->assertSee('Complete your account')->assertSee('Your details')->assertSee('Driving licence number');

        Livewire::test(\App\Filament\Account\Pages\Documents::class)
            ->fillForm(['nationality' => 'Pakistan', 'residency' => 'resident', 'id_number' => '784-1990-1234567-1', 'licence_number' => 'L-77', 'licence_expires_on' => '2030-01-01'], 'detailsForm')
            ->call('saveDetails')
            ->assertHasNoFormErrors([], 'detailsForm')
            ->assertRedirect(\App\Filament\Account\Pages\Home::getUrl(panel: 'account')); // no documents required in this set-up

        $this->assertSame('L-77', $customer->fresh()->licence_number);
        $this->get('/account')->assertOk();
    }
}
