<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and third-party vehicles (brief 29 Oct, Modules 2–3). Additive only:
 *  - suppliers, their cost rates (vehicle-specific or default, with effective dates)
 *  - cars / yachts get a supplier (the old free-text partner_name stays)
 *  - bookings keep a supplier cost snapshot and the bill made from it
 *  - transactions can belong to a supplier (bills); supplier payments are allocated to bills
 *  - quotations / bookings keep why a route distance was typed by hand
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('contact_person')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('type', 30)->default('car_owner');         // car_owner / rental_company / yacht_owner / chauffeur_company / other
            $t->string('status', 20)->default('active')->index(); // active / inactive / pending / blocked
            $t->string('payment_terms')->nullable();
            $t->decimal('opening_balance', 12, 2)->default(0);     // what we owed them before the CRM
            $t->date('opening_balance_date')->nullable();
            $t->string('trn', 30)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('supplier_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('vehicle_type', 10)->nullable();            // car / yacht; empty = the supplier's default
            $t->unsignedBigInteger('vehicle_id')->nullable();
            $t->string('method', 20);                              // per_booking / per_day / per_week / per_month / per_km / percent
            $t->decimal('amount', 12, 2);
            $t->string('partial', 10)->default('pro_rata');        // weeks / months: pro_rata or full
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['supplier_id', 'vehicle_type', 'vehicle_id', 'is_active'], 'supplier_rates_lookup');
        });

        foreach (['cars', 'yachts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('supplier_id')->nullable()->after('partner_name')->constrained('suppliers')->nullOnDelete();
            });
        }

        Schema::table('bookings', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->after('bookable_id')->constrained('suppliers')->nullOnDelete();
            $t->string('supplier_cost_status', 12)->nullable()->after('supplier_id'); // calculated / missing / override / pending
            $t->decimal('supplier_cost', 12, 2)->nullable()->after('supplier_cost_status');
            $t->json('supplier_cost_snapshot')->nullable()->after('supplier_cost');
            $t->foreignId('supplier_bill_id')->nullable()->after('supplier_cost_snapshot')->constrained('transactions')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->after('customer_id')->constrained('suppliers')->nullOnDelete();
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->nullable()->unique();         // SP-00001
            $t->foreignId('supplier_id')->constrained();
            $t->date('date');
            $t->decimal('amount', 12, 2);
            $t->string('method', 20)->nullable();
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reverse_reason')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'date']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete(); // empty = the opening balance
            $t->decimal('amount', 12, 2);
            $t->timestamps();
        });

        foreach (['bookings', 'quotations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('route_override_reason')->nullable()->after('route_source');
                $t->foreignId('route_override_by')->nullable()->after('route_override_reason')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['bookings', 'quotations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('route_override_by');
                $t->dropColumn('route_override_reason');
            });
        }
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::table('transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('supplier_bill_id');
            $t->dropConstrainedForeignId('supplier_id');
            $t->dropColumn(['supplier_cost_status', 'supplier_cost', 'supplier_cost_snapshot']);
        });
        foreach (['cars', 'yachts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        }
        Schema::dropIfExists('supplier_rates');
        Schema::dropIfExists('suppliers');
    }
};
