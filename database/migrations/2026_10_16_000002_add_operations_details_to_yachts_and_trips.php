<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yachts', function (Blueprint $table) {
            $table->unsignedSmallInteger('year_built')->nullable()->after('model');
            $table->unsignedTinyInteger('bathrooms')->nullable()->after('cabins');
            $table->decimal('sunset_price', 10, 2)->nullable()->after('full_day_price'); // sunset / 2-hour slot
            $table->boolean('fuel_included')->default(true)->after('minimum_hours');
            $table->json('addons')->nullable()->after('additional_charges'); // [{name, price, unit}]
            $table->text('terms')->nullable()->after('restrictions');

            $table->string('license_number')->nullable()->after('notes');
            $table->date('license_expiry')->nullable()->index()->after('license_number');
            $table->string('insurance_company')->nullable()->after('license_expiry');
            $table->string('insurance_policy_no')->nullable()->after('insurance_company');
            $table->date('insurance_expiry')->nullable()->index()->after('insurance_policy_no');
            $table->date('next_service_date')->nullable()->after('insurance_expiry');

            $table->string('ownership')->default('own')->after('next_service_date');
            $table->string('partner_name')->nullable()->after('ownership');
            $table->decimal('partner_commission', 5, 2)->nullable()->after('partner_name');

            $table->string('website_badge')->nullable()->after('is_featured');
            $table->string('meta_title')->nullable()->after('website_badge');
            $table->string('meta_description', 300)->nullable()->after('meta_title');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->string('category')->nullable()->after('description'); // sightseeing, sunset, party...
            $table->unsignedSmallInteger('min_guests')->nullable()->after('guest_capacity');
            $table->decimal('child_price', 10, 2)->nullable()->after('price');
            $table->string('meeting_point')->nullable()->after('departure_marina');
            $table->json('itinerary')->nullable()->after('excluded_services'); // [{time, title}]
            $table->json('addons')->nullable()->after('itinerary');
            $table->string('website_badge')->nullable()->after('is_featured');
            $table->string('meta_title')->nullable()->after('website_badge');
            $table->string('meta_description', 300)->nullable()->after('meta_title');
        });
    }

    public function down(): void
    {
        Schema::table('yachts', function (Blueprint $table) {
            $table->dropIndex(['license_expiry']);
            $table->dropIndex(['insurance_expiry']);
            $table->dropColumn([
                'year_built', 'bathrooms', 'sunset_price', 'fuel_included', 'addons', 'terms',
                'license_number', 'license_expiry', 'insurance_company', 'insurance_policy_no',
                'insurance_expiry', 'next_service_date', 'ownership', 'partner_name',
                'partner_commission', 'website_badge', 'meta_title', 'meta_description',
            ]);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn([
                'category', 'min_guests', 'child_price', 'meeting_point', 'itinerary', 'addons',
                'website_badge', 'meta_title', 'meta_description',
            ]);
        });
    }
};
