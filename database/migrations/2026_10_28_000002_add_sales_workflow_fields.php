<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales workflow (28 Oct brief, Priority 3). Additive only:
 * - leads: the assigned agent accepts (or declines) the lead
 * - quotations: how and by whom it was accepted / declined, the exact terms
 *   accepted, and the document request sent after acceptance
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('assignment_status', 20)->nullable()->after('assigned_to');   // pending / accepted
            $table->dateTime('assigned_at')->nullable()->after('assignment_status');
            $table->dateTime('accepted_at')->nullable()->after('assigned_at');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('accepted_via', 20)->nullable()->after('accepted_at');        // portal / link / staff
            $table->string('accepted_name')->nullable()->after('accepted_via');
            $table->string('accepted_ip', 45)->nullable()->after('accepted_name');
            $table->string('accepted_user_agent')->nullable()->after('accepted_ip');
            $table->json('accepted_terms')->nullable()->after('accepted_user_agent');    // the exact offer accepted
            $table->string('rejected_via', 20)->nullable()->after('rejected_at');
            $table->foreignId('document_request_id')->nullable()->after('booking_id')->constrained('document_requests')->nullOnDelete();
            $table->dateTime('emailed_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_request_id');
            $table->dropColumn(['accepted_via', 'accepted_name', 'accepted_ip', 'accepted_user_agent', 'accepted_terms', 'rejected_via', 'emailed_at']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['assignment_status', 'assigned_at', 'accepted_at']);
        });
    }
};
