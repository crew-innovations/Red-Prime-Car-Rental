<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages (brief 28 Oct-2, Phase 17): customer ↔ team and driver ↔ team, per
 * booking. Internal notes live in the same thread but are never shown to the
 * customer or the driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);                                                  // customer / driver
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('subject')->nullable();
            $t->dateTime('last_message_at')->nullable()->index();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();
            $t->index(['kind', 'booking_id']);
            $t->index(['kind', 'customer_id']);
            $t->index(['kind', 'driver_id']);
        });

        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('author', 10);                                                // staff / customer / driver
            $t->text('body');
            $t->boolean('internal')->default(false);                                 // team only
            $t->timestamps();
            $t->index(['chat_thread_id', 'id']);
        });

        Schema::create('chat_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('last_message_id')->default(0);
            $t->timestamps();
            $t->unique(['chat_thread_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
    }
};
