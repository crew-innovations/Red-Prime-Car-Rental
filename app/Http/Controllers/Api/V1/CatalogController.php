<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Http\Resources\Api\YachtTripResource;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\Trip;
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
}
