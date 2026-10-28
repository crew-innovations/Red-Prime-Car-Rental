<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log / activity monitoring (brief 28 Oct-2, Phase 8). Additive only: old
 * rows keep working; the new columns are filled from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->string('role', 40)->nullable()->after('user_id');
            $t->string('outcome', 20)->nullable()->after('event');      // null = success / failed / denied
            $t->string('ip_address', 45)->nullable()->after('note');
            $t->string('user_agent', 255)->nullable()->after('ip_address');
            $t->string('request_id', 40)->nullable()->after('user_agent');
            $t->index(['event', 'created_at']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->dropIndex(['event', 'created_at']);
            $t->dropIndex(['user_id', 'created_at']);
            $t->dropColumn(['role', 'outcome', 'ip_address', 'user_agent', 'request_id']);
        });
    }
};
