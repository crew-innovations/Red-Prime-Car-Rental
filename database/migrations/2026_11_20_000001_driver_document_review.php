<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 20 Nov (Bilal, item 8): drivers upload their own documents; the office approves or rejects them.
 * Additive only. Documents already on file were added by the office, so they start as approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_documents', function (Blueprint $t) {
            $t->string('status', 20)->default('approved')->index()->after('notes'); // pending / approved / rejected
            $t->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $t->string('review_note', 500)->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('driver_documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['status', 'reviewed_at', 'review_note']);
        });
    }
};
