<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job completion reports (brief 28 Oct-2, Phase 6): one PDF per completed job,
 * kept with every version, who made it and what was still missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20)->nullable()->unique();   // JR-00001 (per version)
            $t->unsignedSmallInteger('version')->default(1);
            $t->boolean('is_current')->default(true);
            $t->string('path');                                // private disk
            $t->string('format', 5)->default('pdf');
            $t->unsignedInteger('bytes')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->json('missing')->nullable();                   // required details still empty when made
            $t->string('reason')->nullable();                  // "Completed" / why it was made again
            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['booking_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_reports');
    }
};
