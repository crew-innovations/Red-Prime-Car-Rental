<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Public website assistant log. Separate from CRM Messages. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_chats', function (Blueprint $t) {
            $t->id();
            $t->string('visitor_id', 64)->index();
            $t->string('locale', 5)->default('en');
            $t->string('product_slug')->nullable();
            $t->text('question');
            $t->text('reply');
            $t->string('tool', 20)->nullable();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_chats');
    }
};
