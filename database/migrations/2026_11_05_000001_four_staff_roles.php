<?php

use App\Support\Roles\RoleMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 5 Nov ("Make it simple", Part A): four staff roles.
 *
 * - users.is_system: the one hidden technical Super Admin for Crew Innovations (set with
 *   `php artisan crm:system-account email`); it never shows in role lists or Team.
 * - role_migration_backup: every user's roles before the move, so down() can put them back.
 * - Moves people: Manager → Admin, Sales / Staff → Agent, Finance → Accounts,
 *   Operations → Admin (or Agent, Business setting legacy_operations_role, decision H-33).
 *   Admin, Agent, Driver, Customer and Super Admin are not touched here.
 * - Old roles stay in the roles table (no user on them); nothing is deleted.
 *
 * Report: `php artisan crm:roles-report`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_system')) {
            Schema::table('users', function (Blueprint $t) {
                $t->boolean('is_system')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasTable('role_migration_backup')) {
            Schema::create('role_migration_backup', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->json('old_roles');
                $t->string('new_role', 40)->nullable();
                $t->string('step', 30)->default('four_roles'); // four_roles / system_account
                $t->timestamp('migrated_at');
                $t->timestamp('restored_at')->nullable();
                $t->index(['user_id', 'step']);
            });
        }

        RoleMigration::moveLegacyRoles();
    }

    public function down(): void
    {
        if (Schema::hasTable('role_migration_backup')) {
            RoleMigration::restoreAll();
            Schema::drop('role_migration_backup');
        }
        if (Schema::hasColumn('users', 'is_system')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn('is_system');
            });
        }
    }
};
