<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limousine & transfers (brief 28 Oct-2, Phase 4). Additive only.
 * - transfer_services: airport pickup, hourly hire… (admin can add / order / switch off)
 * - vehicle_categories: Sedan, SUV, Sprinter… ; cars get an optional category
 * - transfer_rates: the price list per category (and optionally per service)
 * - transfer_fixed_routes: fixed prices for known routes
 * - quotations and bookings get the journey fields (one place, no second booking system)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_services', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('journey_type', 20)->default('one_way'); // one_way / return / hourly / custom
            $t->boolean('is_airport')->default(false);           // flight number, terminal, airport fee
            $t->boolean('needs_destination')->default(true);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_published')->default(false);         // offered on the website
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('vehicle_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->unsignedSmallInteger('passengers')->nullable();
            $t->unsignedSmallInteger('luggage')->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_published')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::table('cars', function (Blueprint $t) {
            $t->foreignId('vehicle_category_id')->nullable()->after('type')->constrained('vehicle_categories')->nullOnDelete();
        });

        Schema::create('transfer_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            $t->foreignId('transfer_service_id')->nullable()->constrained('transfer_services')->cascadeOnDelete(); // empty = every service
            $t->string('name')->nullable();
            $t->decimal('base_fee', 10, 2)->nullable();
            $t->decimal('per_km', 10, 2)->nullable();
            $t->decimal('min_fare', 10, 2)->nullable();
            $t->decimal('per_hour', 10, 2)->nullable();
            $t->decimal('min_hours', 5, 2)->nullable();
            $t->decimal('half_day_price', 10, 2)->nullable();
            $t->decimal('half_day_hours', 5, 2)->nullable();
            $t->decimal('full_day_price', 10, 2)->nullable();
            $t->decimal('full_day_hours', 5, 2)->nullable();
            $t->decimal('airport_fee', 10, 2)->nullable();
            $t->decimal('per_stop', 10, 2)->nullable();
            $t->unsignedSmallInteger('free_waiting_minutes')->nullable();
            $t->decimal('waiting_per_hour', 10, 2)->nullable();
            $t->decimal('night_percent', 5, 2)->nullable();
            $t->time('night_from')->nullable();
            $t->time('night_to')->nullable();
            $t->decimal('return_discount_percent', 5, 2)->nullable();
            $t->decimal('toll_fee', 10, 2)->nullable();
            $t->date('starts_on')->nullable();                   // effective dates
            $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['vehicle_category_id', 'transfer_service_id', 'is_active'], 'transfer_rates_lookup');
        });

        Schema::create('transfer_fixed_routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            $t->string('name');
            $t->string('from_match');                            // words found in the pickup address, e.g. "DXB, Dubai International"
            $t->string('to_match');
            $t->boolean('both_ways')->default(true);
            $t->decimal('price', 10, 2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        foreach (['quotations', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('transfer_service_id')->nullable()->constrained('transfer_services')->nullOnDelete();
                $t->foreignId('vehicle_category_id')->nullable()->constrained('vehicle_categories')->nullOnDelete();
                $t->string('journey_type', 20)->nullable();
                $t->string('dropoff_location')->nullable();
                $t->json('stops')->nullable();                   // [{address}]
                $t->dateTime('return_at')->nullable();
                $t->string('flight_number', 20)->nullable();
                $t->string('airline', 60)->nullable();
                $t->string('terminal', 30)->nullable();
                $t->unsignedSmallInteger('luggage')->nullable();
                $t->unsignedSmallInteger('child_seats')->nullable();
                $t->unsignedSmallInteger('waiting_minutes')->nullable();
                $t->decimal('route_km', 8, 2)->nullable();
                $t->unsignedInteger('route_minutes')->nullable();
                $t->decimal('return_route_km', 8, 2)->nullable();
                $t->string('route_source', 20)->nullable();      // google / manual
                $t->json('route')->nullable();                   // what the maps service returned (legs), for the record
                $t->text('special_requests')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['quotations', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('transfer_service_id');
                $t->dropConstrainedForeignId('vehicle_category_id');
                $cols = ['journey_type', 'dropoff_location', 'stops', 'return_at', 'flight_number', 'airline', 'terminal', 'luggage', 'child_seats',
                    'waiting_minutes', 'route_km', 'route_minutes', 'return_route_km', 'route_source', 'route', 'special_requests'];
                $t->dropColumn($cols);
            });
        }
        Schema::dropIfExists('transfer_fixed_routes');
        Schema::dropIfExists('transfer_rates');
        Schema::table('cars', fn (Blueprint $t) => $t->dropConstrainedForeignId('vehicle_category_id'));
        Schema::dropIfExists('vehicle_categories');
        Schema::dropIfExists('transfer_services');
    }
};
