<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable()->index();
            $table->string('whatsapp')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('nationality')->nullable();
            $table->string('residency')->nullable(); // resident / tourist / country
            $table->string('language')->nullable();
            $table->string('type')->default('individual');
            $table->string('company_name')->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_vip')->default(false)->index();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
