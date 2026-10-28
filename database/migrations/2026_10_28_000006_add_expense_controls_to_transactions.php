<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts (28 Oct brief, Priority 8). Additive only; existing money rows are
 * unchanged (no approval needed = approved, not an estimate, not reversed).
 * - vendor: who was paid for an expense
 * - approval_status / approved_by / approved_at: expenses entered by staff
 *   without finance rights wait for approval before they count
 * - is_estimate: an expected cost, shown apart from actual costs
 * - reversed_at / reversed_by / reversal_reason: corrections keep the row
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $t) {
            $t->string('vendor')->nullable()->after('description');
            $t->string('approval_status', 20)->nullable()->after('status');   // null = approved / pending / rejected
            $t->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $t->dateTime('approved_at')->nullable()->after('approved_by');
            $t->boolean('is_estimate')->default(false)->after('approved_at');
            $t->dateTime('reversed_at')->nullable()->after('is_estimate');
            $t->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $t->string('reversal_reason')->nullable()->after('reversed_by');
            $t->index(['type', 'date', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $t) {
            $t->dropIndex(['type', 'date', 'reversed_at']);
            $t->dropConstrainedForeignId('approved_by');
            $t->dropConstrainedForeignId('reversed_by');
            $t->dropColumn(['vendor', 'approval_status', 'approved_at', 'is_estimate', 'reversed_at', 'reversal_reason']);
        });
    }
};
