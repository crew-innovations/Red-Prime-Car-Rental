<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private yacht charters are back (Bilal, 28 Oct), with captain and crew taken
 * from the existing driver module (no second staff system). Additive only:
 * - driver_profiles.work_types: driver / captain / crew
 * - bookings: captain_id, crew_ids, crew_mode (with_crew / bareboat)
 * - yachts.bareboat_allowed: off by default, so no bareboat rental by accident
 * - booking_jobs: one "charter" job per crew member (unique key widened), with an end time
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $t) {
            $t->json('work_types')->nullable()->after('user_id');
        });

        Schema::table('yachts', function (Blueprint $t) {
            $t->boolean('bareboat_allowed')->default(false)->after('captain_included');
        });

        Schema::table('bookings', function (Blueprint $t) {
            $t->string('crew_mode', 20)->nullable()->after('drive_mode');
            $t->foreignId('captain_id')->nullable()->after('driver_id')->constrained('users')->nullOnDelete();
            $t->json('crew_ids')->nullable()->after('captain_id');
        });

        Schema::table('quotations', function (Blueprint $t) {
            $t->string('crew_mode', 20)->nullable()->after('drive_mode');
        });

        Schema::table('booking_jobs', function (Blueprint $t) {
            $t->string('role', 20)->nullable()->after('type');      // charters: captain / crew
            $t->dateTime('ends_at')->nullable()->after('scheduled_at');
            $t->unique(['booking_id', 'type', 'driver_id'], 'booking_jobs_booking_type_person_unique');
        });
        Schema::table('booking_jobs', function (Blueprint $t) {
            $t->dropUnique(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_jobs', function (Blueprint $t) {
            $t->unique(['booking_id', 'type']);
        });
        Schema::table('booking_jobs', function (Blueprint $t) {
            $t->dropUnique('booking_jobs_booking_type_person_unique');
            $t->dropColumn(['role', 'ends_at']);
        });
        Schema::table('quotations', function (Blueprint $t) {
            $t->dropColumn('crew_mode');
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('captain_id');
            $t->dropColumn(['crew_mode', 'crew_ids']);
        });
        Schema::table('yachts', function (Blueprint $t) {
            $t->dropColumn('bareboat_allowed');
        });
        Schema::table('driver_profiles', function (Blueprint $t) {
            $t->dropColumn('work_types');
        });
    }
};
