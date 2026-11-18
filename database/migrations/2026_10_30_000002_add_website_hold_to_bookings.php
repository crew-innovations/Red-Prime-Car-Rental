<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website bookings (M3, 30 Oct, decision H-7: a website booking is made as "Pending payment").
 * - source: where the booking came from ("website"); empty for bookings made by staff.
 * - hold_until: a website booking that is not paid holds the car / yacht / seats until then
 *   (Business settings, 24 hours by default). Cleared once a payment is recorded or staff change the status.
 * New nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('status');
            $table->timestamp('hold_until')->nullable()->after('source');
            $table->index(['status', 'hold_until']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status', 'hold_until']);
            $table->dropColumn(['source', 'hold_until']);
        });
    }
};
