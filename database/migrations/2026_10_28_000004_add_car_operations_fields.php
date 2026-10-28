<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Car operations (28 Oct brief, Priority 4). Additive only:
 * - bookings.return_driver_id: another driver for the collection when the
 *   delivery driver is not free (empty = the same driver, as before)
 * - booking_jobs.approved_at / approved_by: office OK before the car is released
 *   (only used when Business settings ask for it)
 * - damage_reports.charge_amount / applied_at: a damage charge only becomes
 *   payable once a reviewer approved it, and is added to the booking once
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->foreignId('return_driver_id')->nullable()->after('driver_id')->constrained('users')->nullOnDelete();
        });
        Schema::table('booking_jobs', function (Blueprint $t) {
            $t->dateTime('approved_at')->nullable()->after('done_at');
            $t->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('damage_reports', function (Blueprint $t) {
            $t->decimal('charge_amount', 12, 2)->nullable()->after('status');
            $t->dateTime('applied_at')->nullable()->after('charge_amount');
        });
    }

    public function down(): void
    {
        Schema::table('damage_reports', function (Blueprint $t) {
            $t->dropColumn(['charge_amount', 'applied_at']);
        });
        Schema::table('booking_jobs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn('approved_at');
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('return_driver_id');
        });
    }
};
