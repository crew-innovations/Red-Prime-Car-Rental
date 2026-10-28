<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing (28 Oct brief, Priority 2). Additive only:
 * - price_rules: seasonal / special-date / category rates on top of the
 *   standard rates that stay on each car, yacht, trip and package.
 * - price_extras: one catalogue of extras and delivery / collection fees.
 * - quotations and bookings keep the standard (calculated) price next to the
 *   agreed price, the reason for any change and a snapshot of the breakdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service_type', 20);                 // car / yacht / trip / package
            $table->string('applies_to', 20)->default('item');   // item / category / all
            $table->unsignedBigInteger('item_id')->nullable();   // the car / yacht / trip / package
            $table->string('category')->nullable();              // car type or yacht type
            $table->string('drive_mode', 20)->nullable();        // cars: self_drive / with_driver / any
            $table->string('unit', 20);                          // hour / day / week / month / half_day / full_day / guest / trip / any
            $table->string('method', 10)->default('set');        // set = this rate, percent = up or down on the standard rate
            $table->decimal('amount', 12, 2);
            $table->decimal('min_units', 8, 2)->nullable();      // minimum duration while the rule applies
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('weekdays')->nullable();                // 1 (Mon) .. 7 (Sun); empty = every day
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['service_type', 'is_active']);
            $table->index(['service_type', 'item_id']);
        });

        Schema::create('price_extras', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind', 20)->default('extra');        // extra / delivery / collection / fee
            $table->json('service_types')->nullable();           // empty = every service
            $table->string('unit', 20)->default('per_booking');  // per_booking / per_day / per_hour / per_guest
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['quotations', 'bookings'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if ($name === 'bookings') {
                    $table->json('extras')->nullable()->after('extras_amount');   // same lines as the quotation
                }
                $table->string('delivery_method', 20)->nullable()->after('pickup_location');   // office / delivery
                $table->string('return_method', 20)->nullable()->after('delivery_method');     // office / collection
                $table->decimal('standard_amount', 12, 2)->nullable()->after('discount');      // calculated price before any change
                $table->text('adjustment_reason')->nullable()->after('standard_amount');
                $table->foreignId('adjusted_by')->nullable()->after('adjustment_reason')->constrained('users')->nullOnDelete();
                $table->json('price_breakdown')->nullable()->after('adjusted_by');            // snapshot of the calculation
            });
        }
    }

    public function down(): void
    {
        foreach (['quotations', 'bookings'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropConstrainedForeignId('adjusted_by');
                $table->dropColumn(array_merge(
                    ['delivery_method', 'return_method', 'standard_amount', 'adjustment_reason', 'price_breakdown'],
                    $name === 'bookings' ? ['extras'] : [],
                ));
            });
        }
        Schema::dropIfExists('price_extras');
        Schema::dropIfExists('price_rules');
    }
};
