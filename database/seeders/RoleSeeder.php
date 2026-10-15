<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /** Internal user roles from Schedule A14. Permissions are attached in Milestone 2. */
    public const ROLES = ['Super Admin', 'Admin', 'Manager', 'Sales', 'Operations', 'Finance', 'Staff'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
