<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shareable lead forms (brief 28 Oct-2, Phase 16): a secure link that can expire or close after N answers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_forms', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->string('service_type', 20)->nullable();
            $t->text('intro')->nullable();
            $t->json('fields')->nullable();                 // extra questions: key, label, type, required, options
            $t->text('thank_you')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('expires_at')->nullable();
            $t->unsignedInteger('max_submissions')->nullable();
            $t->unsignedInteger('submissions')->default(0);
            $t->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_forms');
    }
};
