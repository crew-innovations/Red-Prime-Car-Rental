<?php

namespace App\Support\Roles;

use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 5 Nov ("Make it simple", Part A): moving people from the old roles to the four staff roles,
 * with a backup row per person so it can be undone (migration down()).
 */
class RoleMigration
{
    /** Old roles → new roles for everyone who still has an old role. Returns [user id => [old, new]]. */
    public static function moveLegacyRoles(): array
    {
        self::ensureRoles();
        $legacy = array_keys(Access::LEGACY);
        $moved = [];

        User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', $legacy))->with('roles')->orderBy('id')
            ->each(function (User $user) use ($legacy, &$moved) {
                $old = $user->getRoleNames()->values()->all();
                $new = collect($old)->map(fn ($r) => Access::canonical($r))->unique()->values();
                // Someone who is Admin through one old role and Agent through another is Admin.
                if ($new->contains('Admin')) {
                    $new = $new->reject(fn ($r) => $r === 'Agent' || $r === 'Accounts')->values();
                }

                DB::transaction(function () use ($user, $old, $new) {
                    DB::table('role_migration_backup')->insert([
                        'user_id' => $user->id,
                        'old_roles' => json_encode($old),
                        'new_role' => $new->diff(['Customer', 'Driver', 'Super Admin'])->first(),
                        'step' => 'four_roles',
                        'migrated_at' => now(),
                    ]);
                    $user->syncRoles($new->all());
                });
                $moved[$user->id] = [$old, $new->all()];
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if ($moved) {
            rescue(fn () => \App\Models\AuditLog::event('permissions', 'Moved '.count($moved).' logins to the four roles (Admin, Agent, Accounts, Driver)',
                collect($moved)->map(fn ($m, $id) => ['user' => $id, 'before' => $m[0], 'after' => $m[1]])->values()->all()), report: false);
        }

        return $moved;
    }

    /**
     * Marks the one hidden technical account (Super Admin, never listed) and moves every other
     * Super Admin to Admin (decision H-34). Backed up like the main move.
     *
     * @return list<string> emails moved from Super Admin to Admin
     */
    public static function markSystemAccount(User $system): array
    {
        self::ensureRoles();
        $moved = [];

        DB::transaction(function () use ($system, &$moved) {
            DB::table('role_migration_backup')->insert([
                'user_id' => $system->id, 'old_roles' => json_encode($system->getRoleNames()->values()->all()),
                'new_role' => 'Super Admin', 'step' => 'system_account', 'migrated_at' => now(),
            ]);
            $system->forceFill(['is_system' => true])->save();
            $system->syncRoles(['Super Admin']);

            User::query()->whereKeyNot($system->id)->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->with('roles')
                ->each(function (User $user) use (&$moved) {
                    $old = $user->getRoleNames()->values()->all();
                    DB::table('role_migration_backup')->insert([
                        'user_id' => $user->id, 'old_roles' => json_encode($old),
                        'new_role' => 'Admin', 'step' => 'system_account', 'migrated_at' => now(),
                    ]);
                    $user->syncRoles(collect($old)->map(fn ($r) => $r === 'Super Admin' ? 'Admin' : $r)->unique()->values()->all());
                    $moved[] = $user->email;
                });
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $moved;
    }

    /** Puts everyone's roles back as they were before the first move (migration down()). */
    public static function restoreAll(): int
    {
        if (! Schema::hasTable('role_migration_backup')) {
            return 0;
        }
        $count = 0;
        // The earliest row per person holds the roles from before any move.
        $first = DB::table('role_migration_backup')->whereNull('restored_at')->orderBy('id')->get()->unique('user_id');
        foreach ($first as $row) {
            $user = User::find($row->user_id);
            if (! $user) {
                continue;
            }
            foreach ((array) json_decode($row->old_roles, true) as $name) {
                Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            $user->syncRoles((array) json_decode($row->old_roles, true));
            if (Schema::hasColumn('users', 'is_system')) {
                $user->forceFill(['is_system' => false])->save();
            }
            $count++;
        }
        DB::table('role_migration_backup')->whereNull('restored_at')->update(['restored_at' => now()]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $count;
    }

    /** One line per staff login: name, email, roles before, roles now, hidden or not. */
    public static function report(): array
    {
        $backup = Schema::hasTable('role_migration_backup')
            ? DB::table('role_migration_backup')->whereNull('restored_at')->orderBy('id')->get()->groupBy('user_id')
            : collect();

        return User::query()->whereNull('customer_id')->with('roles')->orderBy('name')->get()
            ->map(fn (User $u) => [
                'name' => $u->name,
                'email' => $u->email,
                'before' => ($b = $backup->get($u->id)?->first()) ? implode(', ', (array) json_decode($b->old_roles, true)) : '(not moved)',
                'now' => $u->getRoleNames()->implode(', ') ?: '(no role)',
                'hidden' => (bool) ($u->is_system ?? false),
                'active' => (bool) $u->is_active,
            ])->all();
    }

    protected static function ensureRoles(): void
    {
        foreach (['Super Admin', 'Admin', 'Agent', 'Accounts', 'Driver', 'Customer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
