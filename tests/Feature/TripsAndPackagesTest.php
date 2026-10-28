<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Pipeline;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Resources\TripResource\Pages\CreateTrip;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\Quotation;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Yacht Trips without yacht records, and the Trips & Packages module. */
class TripsAndPackagesTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->user('Super Admin'));
    }

    protected function package(array $attrs = []): Package
    {
        return Package::create($attrs + [
            'name' => 'Liwa Safari', 'duration_days' => 3, 'duration_nights' => 2,
            'pricing_mode' => 'per_person', 'price' => 1000, 'booking_mode' => 'departures',
            'default_capacity' => 4, 'is_published' => true,
            'itinerary' => [['title' => 'Day one'], ['title' => 'Day two'], ['title' => 'Day three']],
        ]);
    }

    public function test_a_yacht_trip_is_created_without_any_yacht_record(): void
    {
        Livewire::test(CreateTrip::class)
            ->fillForm([
                'name' => 'Marina Sunset Cruise',
                'operator_name' => 'Blue Sea Charters LLC',
                'duration_minutes' => 120,
                'guest_capacity' => 20,
                'price_unit' => 'per_person',
                'price' => 250,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $trip = Trip::where('name', 'Marina Sunset Cruise')->firstOrFail();
        $this->assertNull($trip->yacht_id);
        $this->assertSame('Blue Sea Charters LLC', $trip->operator_name);

        // Seats are still protected per date.
        $date = $this->tripDate($trip, today()->addDays(3), ['available_slots' => 5]);
        $this->booking($trip, $date->date->copy()->setTime(17, 0), $date->date->copy()->setTime(19, 0), ['trip_date_id' => $date->id, 'guests' => 4]);
        $this->expectException(ValidationException::class);
        $this->booking($trip, $date->date->copy()->setTime(17, 0), $date->date->copy()->setTime(19, 0), ['trip_date_id' => $date->id, 'guests' => 2]);
    }

    public function test_package_with_a_five_day_itinerary_is_saved_and_reordered(): void
    {
        $days = [];
        foreach (['Arrive', 'Desert', 'Oasis', 'Mountains', 'Return'] as $title) {
            $days[(string) \Illuminate\Support\Str::uuid()] = ['title' => $title, 'description' => "{$title} day", 'meals' => ['breakfast']];
        }

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'Five Day Emirates Tour',
                'duration_days' => 5,
                'duration_nights' => 4,
                'pricing_mode' => 'per_person',
                'price' => 3900,
                'currency' => 'AED',
                'booking_mode' => 'on_request',
                'status' => 'active',
                'itinerary' => $days,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Package::where('name', 'Five Day Emirates Tour')->firstOrFail();
        $this->assertSame(['Arrive', 'Desert', 'Oasis', 'Mountains', 'Return'], array_column(array_values($package->itinerary), 'title'));
        $this->assertSame('five-day-emirates-tour', $package->slug);

        // Move "Return" to the top and drop "Oasis".
        $keys = array_keys($days);
        $reordered = [$keys[4] => $days[$keys[4]], $keys[0] => $days[$keys[0]], $keys[1] => $days[$keys[1]], $keys[3] => $days[$keys[3]]];
        Livewire::test(EditPackage::class, ['record' => $package->id])
            ->set('data.itinerary', $reordered) // replace the whole list, as the drag & drop does
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Return', 'Arrive', 'Desert', 'Mountains'], array_column(array_values($package->fresh()->itinerary), 'title'));
    }

    public function test_package_prices_per_person_tiers_offer_fixed_and_departure_price(): void
    {
        $p = $this->package(['price' => 1000, 'price_tiers' => [['min_guests' => 4, 'price' => 850]]]);
        $this->assertSame(['guest', 2, 1000.0, 2000.0], $p->priceFor(2));
        $this->assertSame(['guest', 5, 850.0, 4250.0], $p->priceFor(5));

        $p->update(['sale_price' => 900]);
        $this->assertSame(900.0, $p->fresh()->unitPrice(null, 2));

        $departure = $p->departures()->create(['start_date' => today()->addDays(10), 'price' => 1200]);
        $this->assertSame(['guest', 5, 1200.0, 6000.0], $p->fresh()->priceFor(5, $departure), 'a departure price always wins');

        $fixed = $this->package(['name' => 'Private Hatta', 'pricing_mode' => 'fixed', 'price' => 1800]);
        $this->assertSame(['package', 1, 1800.0, 1800.0], $fixed->priceFor(6));
    }

    public function test_package_departure_places_cannot_be_overbooked(): void
    {
        $p = $this->package(['default_capacity' => 4]);
        $d = $p->departures()->create(['start_date' => today()->addDays(10), 'end_date' => today()->addDays(12)]);

        $make = fn (int $guests) => Booking::create([
            'status' => BookingStatus::Confirmed, 'customer_id' => $this->customer()->id,
            'service_type' => ServiceType::Package, 'bookable_id' => $p->id, 'package_departure_id' => $d->id,
            'start_at' => $d->startsAt(), 'end_at' => $d->endsAt(), 'guests' => $guests,
            'base_amount' => $p->priceFor($guests, $d)[3],
        ]);

        $first = $make(3);
        $this->assertSame('package', $first->bookable_type);
        $this->assertSame('travel_package', $first->revenueCategory());
        $this->assertSame(3000.0, (float) $first->total);

        try {
            $make(2);
            $this->fail('Overbooking was allowed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only 1 place(s) left', collect($e->errors())->flatten()->first());
        }

        // A cancelled booking frees its places again.
        $first->update(['status' => BookingStatus::Cancelled, 'cancel_reason' => 'Changed plans']);
        $make(2);
        $this->assertSame(2, $d->fresh()->bookedGuests());
    }

    public function test_package_quotation_converts_to_a_booking_on_the_same_departure(): void
    {
        $p = $this->package();
        $d = $p->departures()->create(['start_date' => today()->addDays(10)]);
        $lead = \App\Models\Lead::create(['name' => 'Sara', 'phone' => '+971500000001', 'service_type' => 'package', 'interested_in_type' => 'package', 'interested_in_id' => $p->id]);
        $this->assertSame(Pipeline::Yacht, $lead->pipeline, 'package enquiries use the Yacht Trips pipeline');

        $q = Quotation::create([
            'lead_id' => $lead->id, 'service_type' => 'package', 'bookable_id' => $p->id, 'package_departure_id' => $d->id,
            'start_at' => $d->startsAt(), 'end_at' => $d->endsAt(), 'guests' => 2, 'base_amount' => 2000,
            'valid_until' => today()->addDays(5), 'status' => QuotationStatus::Accepted,
        ]);

        Livewire::test(\App\Filament\Resources\QuotationResource\Pages\EditQuotation::class, ['record' => $q->id])
            ->callAction('convert', ['status' => BookingStatus::Confirmed->value]);

        $booking = Booking::where('quotation_id', $q->id)->firstOrFail();
        $this->assertSame($d->id, $booking->package_departure_id);
        $this->assertSame(ServiceType::Package, $booking->service_type);
        $this->assertSame(2, $d->fresh()->bookedGuests());
    }

    public function test_yacht_charter_is_offered_again_with_the_same_pipeline_keys(): void
    {
        $this->assertArrayHasKey('yacht', ServiceType::pickerOptions());
        $this->assertArrayHasKey('package', ServiceType::pickerOptions());
        $this->assertSame('Yachts & Trips', Pipeline::Yacht->getLabel());
        $this->assertSame('Yacht / Trip Selected', Pipeline::Yacht->stageLabel('yacht_selected'), 'only labels changed; the stage keys are the same');
    }

    public function test_package_section_switch_hides_packages_from_the_website(): void
    {
        $this->package();
        $this->assertSame(1, Package::publiclyVisible()->count());
        Category::where('slug', 'packages')->update(['is_enabled' => false]);
        \Illuminate\Support\Facades\Cache::forget('categories.enabled');
        $this->assertSame(0, Package::publiclyVisible()->count());
    }
}
