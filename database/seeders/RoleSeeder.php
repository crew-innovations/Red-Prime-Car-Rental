<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * 5 Nov ("Make it simple", Part A): four staff roles (Admin, Agent, Accounts, Driver), the hidden
     * technical Super Admin and the Customer login. What each role may do is in App\Support\Access.
     * Old roles (Manager, Sales, Operations, Finance, Staff) are not created any more; where they already
     * exist they stay in the table until the role migration has been checked, and count as the new roles.
     */
    public const ROLES = ['Super Admin', 'Admin', 'Agent', 'Accounts', 'Driver', 'Customer'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
