<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yachts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('length_ft')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedSmallInteger('max_guests')->nullable();
            $table->unsignedTinyInteger('cabins')->nullable();
            $table->unsignedTinyInteger('crew_count')->nullable();
            $table->boolean('captain_included')->default(true);
            $table->string('marina')->nullable();
            $table->text('description')->nullable();
            $table->json('amenities')->nullable();
            $table->json('specifications')->nullable();
            $table->text('restrictions')->nullable();
            $table->decimal('hourly_price', 10, 2)->nullable();
            $table->decimal('half_day_price', 10, 2)->nullable();
            $table->decimal('full_day_price', 10, 2)->nullable();
            $table->unsignedSmallInteger('minimum_hours')->nullable();
            $table->json('additional_charges')->nullable(); // [{name, price}]
            $table->decimal('deposit', 10, 2)->nullable();
            $table->string('status')->default('available')->index();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes(); // archive
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yachts');
    }
};
