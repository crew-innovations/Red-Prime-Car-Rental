<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            CategorySeeder::class,
            LeadSourceSeeder::class,
        ]);

        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@redprime.test')],
            [
                'name' => env('ADMIN_NAME', 'Super Admin'),
                'password' => env('ADMIN_PASSWORD', 'change-me'),
                'is_active' => true,
            ],
        );

        $admin->assignRole('Super Admin');
    }
}
