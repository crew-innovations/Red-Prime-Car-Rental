<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 5 Nov ("Make it simple", Part D, S5): fuel.
 *
 * - cars: tank size, fuel grade (Special 95 / Super 98 / Diesel / Electric) and the current level in
 *   eighths. The old free-text "fuel" (Petrol / Diesel…) stays for the website.
 * - bookings: fuel level at handover / return in eighths. The old quarter columns stay and are still
 *   written (nearest quarter), so nothing old is rewritten.
 * - fuel_entries: every fill-up (from a driver's phone, or entered by Accounts / Admin). A driver's
 *   amount is only a claim until Accounts confirms it; confirming creates the fuel expense.
 *   Never deleted: a wrong one is rejected with a reason.
 * - fuel_prices: price per litre per grade and month (UAE prices change every month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $t) {
            if (! Schema::hasColumn('cars', 'tank_litres')) {
                $t->decimal('tank_litres', 6, 1)->nullable();
                $t->string('fuel_grade', 20)->nullable();           // special95 / super98 / diesel / electric
                $t->unsignedTinyInteger('fuel_level_8')->nullable(); // 0 = empty … 8 = full
                $t->timestamp('fuel_level_at')->nullable();
            }
        });

        Schema::table('bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('bookings', 'delivery_fuel_8')) {
                $t->unsignedTinyInteger('delivery_fuel_8')->nullable();
                $t->unsignedTinyInteger('return_fuel_8')->nullable();
                $t->decimal('fuel_charge_proposed', 10, 2)->nullable(); // what the CRM worked out at return
                $t->string('fuel_charge_note', 255)->nullable();       // why staff changed it
            }
        });

        if (! Schema::hasTable('fuel_entries')) {
            Schema::create('fuel_entries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('car_id')->constrained('cars')->cascadeOnDelete();
                $t->timestamp('filled_at');
                $t->unsignedInteger('km')->nullable();
                $t->decimal('litres', 7, 2);
                $t->decimal('amount', 10, 2);
                $t->string('fuel_grade', 20)->nullable();
                $t->string('station', 120)->nullable();
                $t->boolean('full_tank')->default(true);
                $t->string('receipt_path')->nullable();               // private disk
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // who filled
                $t->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
                $t->foreignId('booking_job_id')->nullable()->constrained('booking_jobs')->nullOnDelete();
                $t->string('status', 12)->default('waiting');        // waiting / confirmed / rejected
                $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('confirmed_at')->nullable();
                $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $t->string('reject_reason', 255)->nullable();
                $t->string('client_id', 64)->nullable()->unique();   // the phone's id, so a resent tap never saves twice
                $t->string('notes', 500)->nullable();
                $t->timestamps();
                $t->index(['car_id', 'filled_at']);
                $t->index(['status', 'filled_at']);
            });
        }

        if (! Schema::hasTable('fuel_prices')) {
            Schema::create('fuel_prices', function (Blueprint $t) {
                $t->id();
                $t->string('fuel_grade', 20);
                $t->date('month');                                     // first day of the month
                $t->decimal('price_per_litre', 6, 2);
                $t->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['fuel_grade', 'month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_prices');
        Schema::dropIfExists('fuel_entries');
        Schema::table('bookings', function (Blueprint $t) {
            if (Schema::hasColumn('bookings', 'delivery_fuel_8')) {
                $t->dropColumn(['delivery_fuel_8', 'return_fuel_8', 'fuel_charge_proposed', 'fuel_charge_note']);
            }
        });
        Schema::table('cars', function (Blueprint $t) {
            if (Schema::hasColumn('cars', 'tank_litres')) {
                $t->dropColumn(['tank_litres', 'fuel_grade', 'fuel_level_8', 'fuel_level_at']);
            }
        });
    }
};
