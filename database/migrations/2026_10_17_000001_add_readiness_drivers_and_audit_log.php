<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Readiness on cars and yachts (cleaning / inspection after a return), kept
 *   separate from the booking-driven status.
 * - Default driver-to-car assignments, and a notes field for driver profiles.
 * - Audit log of changes to important records.
 * Additive only: no existing column is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cars', 'yachts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('readiness')->default('ready')->index()->after('status'); // ready / cleaning / inspection
                $t->string('readiness_note')->nullable()->after('readiness');
            });
        }

        Schema::table('bookings', function (Blueprint $t) {
            $t->string('inspection_result')->nullable()->after('return_condition'); // ready / cleaning / inspection / maintenance
        });

        Schema::table('users', function (Blueprint $t) {
            $t->text('notes')->nullable()->after('phone'); // driver profile notes (licence, languages, …)
        });

        Schema::create('car_driver', function (Blueprint $t) {
            $t->id();
            $t->foreignId('car_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['car_id', 'user_id']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event');                      // created / updated / deleted / restored / override / …
            $t->nullableMorphs('auditable');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('note')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('car_driver');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notes'));

        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('inspection_result'));

        foreach (['cars', 'yachts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['readiness']);
                $t->dropColumn(['readiness', 'readiness_note']);
            });
        }
    }
};
