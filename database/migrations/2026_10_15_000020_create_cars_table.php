<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('type')->nullable();           // SUV, Sedan, Sports, Luxury...
            $table->string('transmission')->nullable();   // Automatic / Manual
            $table->string('fuel')->nullable();
            $table->unsignedTinyInteger('seats')->nullable();
            $table->string('colour')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->json('specifications')->nullable();
            $table->text('terms')->nullable();
            $table->decimal('daily_price', 10, 2)->nullable();
            $table->decimal('weekly_price', 10, 2)->nullable();
            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->decimal('deposit', 10, 2)->nullable();
            $table->string('status')->default('available')->index();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('car_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_maintenances');
        Schema::dropIfExists('cars');
    }
};
