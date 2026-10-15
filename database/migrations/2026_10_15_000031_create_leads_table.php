<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type')->nullable();       // car / yacht / trip / other
            $table->nullableMorphs('interested_in');           // the specific car / yacht / trip
            $table->text('message')->nullable();
            $table->date('preferred_date')->nullable();
            $table->string('status')->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->string('lost_reason')->nullable();
            // Attribution, filled automatically by website forms / integrations
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('external_id')->nullable()->index(); // id from Meta/TikTok etc.
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
