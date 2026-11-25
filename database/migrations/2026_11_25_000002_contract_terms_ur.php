<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 25 Nov: freeze Urdu terms on the contract when signed (with EN / AR). Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_contracts', 'terms_ur')) {
                $t->longText('terms_ur')->nullable()->after('terms_ar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $t) {
            if (Schema::hasColumn('rental_contracts', 'terms_ur')) {
                $t->dropColumn('terms_ur');
            }
        });
    }
};
