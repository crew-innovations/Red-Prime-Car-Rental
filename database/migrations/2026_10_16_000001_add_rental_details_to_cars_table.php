<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            // Website card specs
            $table->string('engine')->nullable()->after('fuel');
            $table->unsignedSmallInteger('horsepower')->nullable()->after('engine');
            $table->unsignedTinyInteger('doors')->nullable()->after('seats');
            $table->unsignedTinyInteger('luggage')->nullable()->after('doors');
            $table->string('interior_colour')->nullable()->after('colour');
            $table->string('vin')->nullable()->after('registration_number');
            $table->string('branch')->nullable()->after('vin');

            // Extra pricing
            $table->decimal('hourly_price', 10, 2)->nullable()->after('deposit');
            $table->decimal('chauffeur_daily_price', 10, 2)->nullable()->after('hourly_price');

            // Rental rules
            $table->unsignedSmallInteger('min_rental_days')->default(1)->after('chauffeur_daily_price');
            $table->unsignedInteger('daily_km_limit')->nullable()->after('min_rental_days');
            $table->decimal('extra_km_charge', 8, 2)->nullable()->after('daily_km_limit');
            $table->unsignedTinyInteger('min_driver_age')->default(21)->after('extra_km_charge');
            $table->decimal('salik_charge', 8, 2)->nullable()->after('min_driver_age');
            $table->boolean('delivery_available')->default(true)->after('salik_charge');
            $table->decimal('delivery_fee', 8, 2)->nullable()->after('delivery_available');
            $table->string('insurance_type')->default('basic')->after('delivery_fee');
            $table->json('addons')->nullable()->after('insurance_type'); // [{name, price, unit}]

            // Documents & compliance
            $table->date('registration_expiry')->nullable()->index()->after('addons');
            $table->string('insurance_company')->nullable()->after('registration_expiry');
            $table->string('insurance_policy_no')->nullable()->after('insurance_company');
            $table->date('insurance_expiry')->nullable()->index()->after('insurance_policy_no');
            $table->date('next_service_date')->nullable()->after('insurance_expiry');
            $table->unsignedInteger('next_service_km')->nullable()->after('next_service_date');

            // Ownership
            $table->string('ownership')->default('own')->after('next_service_km'); // own / partner
            $table->string('partner_name')->nullable()->after('ownership');
            $table->decimal('partner_commission', 5, 2)->nullable()->after('partner_name'); // %

            // Website & SEO
            $table->string('website_badge')->nullable()->after('is_featured');
            $table->string('meta_title')->nullable()->after('website_badge');
            $table->string('meta_description', 300)->nullable()->after('meta_title');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropIndex(['registration_expiry']);
            $table->dropIndex(['insurance_expiry']);
            $table->dropColumn([
                'engine', 'horsepower', 'doors', 'luggage', 'interior_colour', 'vin', 'branch',
                'hourly_price', 'chauffeur_daily_price', 'min_rental_days', 'daily_km_limit',
                'extra_km_charge', 'min_driver_age', 'salik_charge', 'delivery_available',
                'delivery_fee', 'insurance_type', 'addons', 'registration_expiry',
                'insurance_company', 'insurance_policy_no', 'insurance_expiry',
                'next_service_date', 'next_service_km', 'ownership', 'partner_name',
                'partner_commission', 'website_badge', 'meta_title', 'meta_description',
            ]);
        });
    }
};
