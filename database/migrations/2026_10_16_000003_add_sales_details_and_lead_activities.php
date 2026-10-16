<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('whatsapp')->nullable()->after('phone');
            $table->string('priority')->default('warm')->index()->after('status'); // hot / warm / cold
            $table->date('end_date')->nullable()->after('preferred_date');
            $table->unsignedSmallInteger('guests')->nullable()->after('end_date');
            $table->decimal('budget', 12, 2)->nullable()->after('guests');
            $table->timestamp('last_contacted_at')->nullable()->after('next_follow_up_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_contact')->default('whatsapp')->after('language'); // whatsapp / phone / email
            $table->json('tags')->nullable()->after('is_vip');
            $table->date('date_of_birth')->nullable()->after('nationality');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // call / whatsapp / email / meeting / note
            $table->string('outcome')->nullable(); // reached / no_answer / interested / not_interested
            $table->text('body')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['preferred_contact', 'tags', 'date_of_birth']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn(['whatsapp', 'priority', 'end_date', 'guests', 'budget', 'last_contacted_at']);
        });
    }
};
