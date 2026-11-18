<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 18 Nov 2026 (meeting minutes, Hani Ahmed / Hifza Anwaar; Bilal: "add contract features same as on fleet").
 * Rental contracts and the booking form changes. Additive only: new tables and new nullable columns,
 * nothing renamed or removed, so the down() simply drops what was added.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Full customer details for the contract (meeting item 6) and the blacklist.
        Schema::table('customers', function (Blueprint $t) {
            // Resident / GCC / tourist is the existing "residency" column.
            $t->string('id_number', 40)->nullable();                         // Emirates ID (or national ID for GCC)
            $t->date('id_expires_on')->nullable();
            $t->string('passport_number', 40)->nullable();
            $t->date('passport_expires_on')->nullable();
            $t->string('licence_number', 40)->nullable();
            $t->string('licence_country', 60)->nullable();
            $t->date('licence_issued_on')->nullable();
            $t->date('licence_expires_on')->nullable();
            $t->string('trade_licence_number', 60)->nullable();              // company customers
            $t->date('trade_licence_expires_on')->nullable();
            $t->dateTime('blacklisted_at')->nullable()->index();
            $t->string('blacklist_reason')->nullable();
            $t->foreignId('blacklisted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Document number / issue date (from OCR or typed) on each uploaded document.
        Schema::table('customer_documents', function (Blueprint $t) {
            $t->string('number', 60)->nullable()->after('type');
            $t->date('issued_on')->nullable()->after('number');
            $t->json('ocr')->nullable()->after('notes');                    // what the reader found, kept for checking
        });

        // Booking form (meeting items 2, 3, 4) and the agreement fields.
        Schema::table('bookings', function (Blueprint $t) {
            $t->boolean('delivery_fee_on')->nullable();
            $t->decimal('delivery_fee_amount', 10, 2)->nullable();
            $t->boolean('collection_fee_on')->nullable();
            $t->decimal('collection_fee_amount', 10, 2)->nullable();
            $t->string('deposit_mode', 12)->nullable();                      // security / none
            $t->decimal('no_deposit_fee', 10, 2)->nullable();
            $t->unsignedInteger('km_limit_per_day')->nullable();             // empty = the car's own limit
            $t->decimal('extra_km_rate', 8, 2)->nullable();
            $t->string('rental_term', 12)->nullable();                       // short / long / lease
            $t->string('sales_source', 60)->nullable();                      // walk-in, website, WhatsApp, marketplace…
        });

        Schema::create('rental_contracts', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->nullable()->unique();
            $t->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 12)->default('individual');                  // individual / company / monthly
            $t->string('status', 12)->default('draft')->index();            // draft / open / closed / cancelled
            $t->string('billing_period', 10)->default('daily');             // daily / weekly / monthly
            $t->boolean('auto_renew')->default(false);
            $t->date('next_invoice_on')->nullable()->index();
            $t->string('lpo_number', 60)->nullable();                        // company: purchase order
            $t->string('company_contact')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->dateTime('signed_at')->nullable();
            $t->string('signed_by_name', 120)->nullable();
            $t->string('signed_via', 12)->nullable();                        // office / driver / link
            $t->string('sign_token', 64)->nullable()->unique();             // customer signs through a link
            $t->dateTime('sign_token_expires_at')->nullable();
            $t->longText('terms_en')->nullable();                            // the terms as agreed (copied when signed)
            $t->longText('terms_ar')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('closing')->nullable();                                 // closing summary as calculated
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('contract_drivers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_contract_id')->constrained()->cascadeOnDelete();
            $t->boolean('is_main')->default(false);
            $t->string('name');
            $t->string('phone', 30)->nullable();
            $t->string('nationality', 60)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('id_number', 40)->nullable();                         // Emirates ID or passport
            $t->date('id_expires_on')->nullable();
            $t->string('licence_number', 40)->nullable();
            $t->string('licence_country', 60)->nullable();
            $t->date('licence_issued_on')->nullable();
            $t->date('licence_expires_on')->nullable();
            $t->decimal('charge', 10, 2)->nullable();                        // additional driver charge
            $t->timestamps();
        });

        Schema::create('contract_vehicle_swaps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_car_id')->nullable()->constrained('cars')->nullOnDelete();
            $t->foreignId('to_car_id')->nullable()->constrained('cars')->nullOnDelete();
            $t->dateTime('swapped_at');
            $t->unsignedInteger('from_km_out')->nullable();                  // the first car's odometer at handover
            $t->unsignedInteger('from_km_in')->nullable();                   // and when it came back
            $t->unsignedTinyInteger('from_fuel_in_8')->nullable();
            $t->unsignedInteger('to_km_out')->nullable();
            $t->unsignedTinyInteger('to_fuel_out_8')->nullable();
            $t->string('reason');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('contract_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_contract_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('path');                                              // PDF on the private disk
            $t->string('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['rental_contract_id', 'version']);
        });

        // Damage OUT / IN already comes from damage_reports.stage; the area of the car is new.
        Schema::table('damage_reports', function (Blueprint $t) {
            $t->string('area', 60)->nullable()->after('stage');
        });

        // Monthly contracts: one invoice per period.
        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignId('rental_contract_id')->nullable()->constrained()->nullOnDelete();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->date('due_on')->nullable();
            $t->unique(['rental_contract_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique(['rental_contract_id', 'period_start']);
            $t->dropConstrainedForeignId('rental_contract_id');
            $t->dropColumn(['period_start', 'period_end', 'due_on']);
        });
        Schema::table('damage_reports', fn (Blueprint $t) => $t->dropColumn('area'));
        Schema::dropIfExists('contract_versions');
        Schema::dropIfExists('contract_vehicle_swaps');
        Schema::dropIfExists('contract_drivers');
        Schema::dropIfExists('rental_contracts');
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropColumn(['delivery_fee_on', 'delivery_fee_amount', 'collection_fee_on', 'collection_fee_amount', 'deposit_mode', 'no_deposit_fee',
                'km_limit_per_day', 'extra_km_rate', 'rental_term', 'sales_source']);
        });
        Schema::table('customer_documents', fn (Blueprint $t) => $t->dropColumn(['number', 'issued_on', 'ocr']));
        Schema::table('customers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('blacklisted_by');
            $t->dropColumn(['id_number', 'id_expires_on', 'passport_number', 'passport_expires_on', 'licence_number', 'licence_country',
                'licence_issued_on', 'licence_expires_on', 'trade_licence_number', 'trade_licence_expires_on', 'blacklisted_at', 'blacklist_reason']);
        });
    }
};
