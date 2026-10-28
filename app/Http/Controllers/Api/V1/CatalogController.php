<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\FleetResource;
use App\Http\Resources\Api\PackageResource;
use App\Http\Resources\Api\YachtTripResource;
use App\Models\Car;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\Trip;
use App\Models\Yacht;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public, read-only catalogue for the website. Only items that are published,
 * active and in an enabled website section are returned; anything else is a 404,
 * even when its slug is known.
 */
class CatalogController extends Controller
{
    public function yachtTrips(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'featured' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $trips = Trip::query()
            ->publiclyVisible()
            ->where('status', TripStatus::Active)
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->with(['media', 'upcomingDates'])
            ->orderBy('sort_order')
            ->paginate((int) $request->input('per_page', 12))
            ->withQueryString();

        return YachtTripResource::collection($trips);
    }

    public function yachtTrip(string $slug): YachtTripResource
    {
        $trip = Trip::query()
            ->publiclyVisible()
            ->where('status', TripStatus::Active)
            ->where('slug', $slug)
            ->with(['media', 'upcomingDates'])
            ->firstOrFail();

        return (new YachtTripResource($trip))->additional(['detail' => true]);
    }

    public function packages(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'featured' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $packages = Package::query()
            ->publiclyVisible()
            ->active()
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $request->string('category'))->where('is_active', true)))
            ->when($request->filled('type'), fn ($q) => $q->where('product_type', $request->string('type')))
            ->with(['media', 'category', 'upcomingDepartures'])
            ->orderBy('sort_order')
            ->paginate((int) $request->input('per_page', 12))
            ->withQueryString();

        return PackageResource::collection($packages);
    }

    public function package(string $slug): PackageResource
    {
        $package = Package::query()
            ->publiclyVisible()
            ->active()
            ->where('slug', $slug)
            ->with(['media', 'category', 'upcomingDepartures'])
            ->firstOrFail();

        return (new PackageResource($package))->additional(['detail' => true]);
    }

    public function packageCategories(): JsonResponse
    {
        $categories = PackageCategory::query()
            ->where('is_active', true)
            ->withCount(['packages as packages_count' => fn ($q) => $q->publiclyVisible()->active()])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (PackageCategory $c) => [
                'slug' => $c->slug,
                'name' => $c->name,
                'description' => $c->description,
                'packages_count' => (int) $c->packages_count,
            ]);

        $types = Package::query()->publiclyVisible()->active()->whereNotNull('product_type')->distinct()->orderBy('product_type')->pluck('product_type');

        return response()->json(['data' => $categories, 'product_types' => $types]);
    }

    /* ---------- Cars and yachts (28 Oct, Priority 6.2) ---------- */

    public function cars(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'featured' => ['sometimes', 'boolean'],
            'brand' => ['sometimes', 'string', 'max:100'],
            'body' => ['sometimes', 'string', 'max:100'],
            'mode' => ['sometimes', 'in:self_drive,with_driver'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $cars = Car::query()->publiclyVisible()
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->string('brand')))
            ->when($request->filled('body'), fn ($q) => $q->where('type', $request->string('body')))
            ->when($request->input('mode') === 'self_drive', fn ($q) => $q->where('self_drive', true))
            ->when($request->input('mode') === 'with_driver', fn ($q) => $q->where('with_driver', true))
            ->with('media')
            ->orderBy('sort_order')->orderBy('name')
            ->paginate((int) $request->input('per_page', 12))
            ->withQueryString();

        return FleetResource::collection($cars);
    }

    public function car(string $slug): FleetResource
    {
        $car = Car::query()->publiclyVisible()->where('slug', $slug)->with('media')->firstOrFail();

        return (new FleetResource($car))->additional(['detail' => true]);
    }

    public function yachts(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'featured' => ['sometimes', 'boolean'],
            'guests' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $yachts = Yacht::query()->publiclyVisible()
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->filled('guests'), fn ($q) => $q->whereRaw('COALESCE(max_guests, capacity, 0) >= ?', [(int) $request->input('guests')]))
            ->with('media')
            ->orderBy('sort_order')->orderBy('name')
            ->paginate((int) $request->input('per_page', 12))
            ->withQueryString();

        return FleetResource::collection($yachts);
    }

    public function yacht(string $slug): FleetResource
    {
        $yacht = Yacht::query()->publiclyVisible()->where('slug', $slug)->with('media')->firstOrFail();

        return (new FleetResource($yacht))->additional(['detail' => true]);
    }

    /* ---------- Limousine & transfers (28 Oct-2, 4.5) ---------- */

    public function transferServices(): JsonResponse
    {
        $services = \App\Models\TransferService::query()->where('is_active', true)->where('is_published', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($s) => [
                'slug' => $s->slug, 'name' => $s->name, 'description' => $s->description,
                'pricing' => $s->journey_type === 'hourly' ? 'hourly' : 'route',
                'needs' => array_values(array_filter(['pickup', $s->needs_destination && $s->journey_type !== 'hourly' ? 'dropoff' : null, $s->journey_type === 'hourly' ? 'end_at' : null, $s->is_airport ? 'flight_number' : null])),
                'return_journey' => $s->journey_type !== 'hourly',
            ]);

        return response()->json(['data' => $services]);
    }

    public function vehicleCategories(): JsonResponse
    {
        $categories = \App\Models\VehicleCategory::query()->where('is_active', true)->where('is_published', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => [
                'slug' => $c->slug, 'name' => $c->name, 'description' => $c->description,
                'passengers' => $c->passengers, 'luggage' => $c->luggage,
                'from_price' => ($rate = \App\Models\TransferRate::for($c->id, null)) && $rate->min_fare ? ['amount' => (float) $rate->min_fare, 'currency' => 'AED', 'label' => 'minimum fare'] : null,
            ]);

        return response()->json(['data' => $categories]);
    }
}
