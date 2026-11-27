<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 27 Nov 2026: an extra label on a lead, booking or quotation.
 * The real status (pipeline, booking status, quotation status) is unchanged.
 * Additive: a new table and a nullable column on each record. down() drops only these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_statuses', function (Blueprint $t) {
            $t->id();
            $t->string('record_type', 20);
            $t->string('name', 80);
            $t->string('color', 20)->default('gray');
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['record_type', 'name']);
        });

        foreach (['leads', 'bookings', 'quotations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('custom_status_id')->nullable()->constrained('custom_statuses')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['quotations', 'bookings', 'leads'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('custom_status_id');
            });
        }
        Schema::dropIfExists('custom_statuses');
    }
};
