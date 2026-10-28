<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional price approval (brief 28 Oct-2, 4.4; Bilal: build it, switched off).
 * When "Discounts above the limit need a manager's OK" is on, a quotation or booking
 * priced below the limit waits (pending) instead of being refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotations', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('price_approval', 20)->nullable();   // null / pending / approved
                $t->foreignId('price_approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('price_approved_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['quotations', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('price_approved_by');
                $t->dropColumn(['price_approval', 'price_approved_at']);
            });
        }
    }
};
