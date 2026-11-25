<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 25 Nov 2026: the driver signs the rental contract from their own link, same as the hirer.
 * Additive: new nullable columns. down() drops only these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $t) {
            $t->string('driver_sign_token', 64)->nullable()->unique()->after('sign_token_expires_at');
            $t->dateTime('driver_sign_token_expires_at')->nullable()->after('driver_sign_token');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $t) {
            $t->dropColumn(['driver_sign_token', 'driver_sign_token_expires_at']);
        });
    }
};
