<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yacht_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('departure_marina')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('guest_capacity')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('price_unit')->default('per_person'); // per_person / per_trip
            $table->json('additional_charges')->nullable();
            $table->json('included_services')->nullable();
            $table->json('excluded_services')->nullable();
            $table->text('booking_requirements')->nullable();
            $table->text('cancellation_terms')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('trip_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('available_slots')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->index(['trip_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_dates');
        Schema::dropIfExists('trips');
    }
};
