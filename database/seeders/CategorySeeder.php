<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => Category::CARS, 'name' => 'Cars', 'sort_order' => 1],
            ['slug' => Category::YACHTS, 'name' => 'Yachts', 'sort_order' => 2],
            ['slug' => Category::YACHT_TRIPS, 'name' => 'Yacht Trips & Experiences', 'sort_order' => 3],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(['slug' => $category['slug']], $category + ['is_enabled' => true]);
        }
    }
}
