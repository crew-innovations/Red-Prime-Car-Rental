<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 of the 4 Nov brief (section 15): driver portal upgrade, live location, unavailable
 * time, dispatch board. Additive only; down() removes exactly what up() adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_jobs', function (Blueprint $t) {
            $t->dateTime('declined_at')->nullable();
            $t->string('decline_reason', 255)->nullable();
            $t->dateTime('started_at')->nullable();       // "on the way"
            $t->unsignedSmallInteger('late_minutes')->nullable();
            $t->dateTime('late_reported_at')->nullable();
        });

        // One row per action sent from a phone, so a retry on a weak signal never does it twice.
        Schema::create('driver_actions', function (Blueprint $t) {
            $t->id();
            $t->string('client_action_id', 64)->unique();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('booking_job_id')->nullable()->constrained('booking_jobs')->nullOnDelete();
            $t->string('action', 30);
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // Live location, only while a job is active (H-23: switched off until Bilal decides).
        Schema::create('driver_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('booking_job_id')->nullable()->constrained('booking_jobs')->nullOnDelete();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->unsignedInteger('accuracy')->nullable();  // metres
            $t->unsignedSmallInteger('speed')->nullable(); // km/h
            $t->dateTime('recorded_at');
            $t->timestamp('created_at')->nullable();
            $t->index(['user_id', 'recorded_at']);
            $t->index('recorded_at');
        });

        Schema::create('driver_unavailability', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('reason', 120)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['user_id', 'starts_at', 'ends_at']);
        });

        Schema::table('damage_reports', function (Blueprint $t) {
            $t->string('kind', 20)->default('damage');     // damage / accident
            $t->string('police_report_no', 60)->nullable();
            $t->string('location', 255)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('client_action_id', 64)->nullable()->unique();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('locale'));
        Schema::table('damage_reports', function (Blueprint $t) {
            $t->dropUnique(['client_action_id']);
            $t->dropColumn(['kind', 'police_report_no', 'location', 'lat', 'lng', 'client_action_id']);
        });
        Schema::dropIfExists('driver_unavailability');
        Schema::dropIfExists('driver_locations');
        Schema::dropIfExists('driver_actions');
        Schema::table('booking_jobs', fn (Blueprint $t) => $t->dropColumn(['declined_at', 'decline_reason', 'started_at', 'late_minutes', 'late_reported_at']));
    }
};
