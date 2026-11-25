<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 25 Nov 2026: reference rental contract PDF (Shahem-style layout) + dual signatures.
 * Additive only: new nullable columns. down() drops what was added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->date('id_issued_on')->nullable()->after('id_number');
            $t->date('passport_issued_on')->nullable()->after('passport_number');
            $t->string('licence_place_of_issue', 120)->nullable()->after('licence_country');
        });

        Schema::table('contract_drivers', function (Blueprint $t) {
            $t->date('id_issued_on')->nullable()->after('id_number');
            $t->string('licence_place_of_issue', 120)->nullable()->after('licence_country');
        });

        Schema::table('rental_contracts', function (Blueprint $t) {
            $t->dateTime('driver_signed_at')->nullable()->after('signed_via');
            $t->string('driver_signed_by_name', 120)->nullable()->after('driver_signed_at');
            $t->string('driver_signed_via', 24)->nullable()->after('driver_signed_by_name');
            $t->string('card_last4', 4)->nullable()->after('notes');
            $t->string('card_brand', 40)->nullable()->after('card_last4');
            $t->string('card_expires_on', 7)->nullable()->after('card_brand'); // MM/YYYY
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $t) {
            $t->dropColumn(['driver_signed_at', 'driver_signed_by_name', 'driver_signed_via', 'card_last4', 'card_brand', 'card_expires_on']);
        });
        Schema::table('contract_drivers', function (Blueprint $t) {
            $t->dropColumn(['id_issued_on', 'licence_place_of_issue']);
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['id_issued_on', 'passport_issued_on', 'licence_place_of_issue']);
        });
    }
};
