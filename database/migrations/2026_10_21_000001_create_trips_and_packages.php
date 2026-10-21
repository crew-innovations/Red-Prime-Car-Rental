<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trips & Packages (multi-day tours and full packages), plus small additive
 * columns so yacht trips no longer depend on an owned-yacht record.
 * Additive only: nothing existing is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('product_type')->nullable();          // one_day / multi_day / safari / full_package … (editable list)
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->unsignedSmallInteger('duration_nights')->nullable();
            $table->json('destinations')->nullable();
            $table->string('start_location')->nullable();
            $table->string('meeting_point')->nullable();
            $table->string('booking_mode')->default('on_request'); // departures / on_request
            $table->unsignedSmallInteger('booking_cutoff_days')->nullable();
            $table->unsignedSmallInteger('min_guests')->nullable();
            $table->unsignedSmallInteger('max_guests')->nullable();  // per booking
            $table->unsignedSmallInteger('default_capacity')->nullable(); // per departure
            $table->string('pricing_mode')->default('per_person');  // per_person / fixed
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('child_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('AED');
            $table->json('price_tiers')->nullable();                // [{min_guests, price}]
            $table->json('itinerary')->nullable();                  // [{title, description, destinations, activities, included, meals, accommodation, image}]
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->json('activities')->nullable();
            $table->text('accommodation')->nullable();
            $table->text('meals')->nullable();
            $table->text('transport')->nullable();
            $table->text('requirements')->nullable();
            $table->text('terms')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_featured')->default(false);
            $table->string('website_badge')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('package_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();   // empty = package default
            $table->decimal('price', 12, 2)->nullable();            // empty = package price
            $table->boolean('is_available')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['package_id', 'start_date']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('package_departure_id')->nullable()->after('trip_date_id')->constrained()->nullOnDelete();
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('package_departure_id')->nullable()->after('trip_date_id')->constrained()->nullOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('package_departure_id')->nullable()->after('interested_in_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('trip_date_id')->nullable()->after('package_departure_id');
        });

        Schema::table('trips', function (Blueprint $table) {
            // Who runs the trip (the yacht is provided by an outside operator).
            $table->string('operator_name')->nullable()->after('yacht_id');
            $table->text('customer_instructions')->nullable()->after('booking_requirements');
        });

        // Website section switch for the new product type (same table as Cars / Yacht Trips).
        if (! DB::table('categories')->where('slug', 'packages')->exists()) {
            DB::table('categories')->insert([
                'name' => 'Trips & Packages', 'slug' => 'packages', 'is_enabled' => true,
                'sort_order' => (int) DB::table('categories')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('categories')->where('slug', 'packages')->delete();

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['operator_name', 'customer_instructions']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_departure_id');
            $table->dropColumn('trip_date_id');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_departure_id');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_departure_id');
        });
        Schema::dropIfExists('package_departures');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('package_categories');
    }
};
