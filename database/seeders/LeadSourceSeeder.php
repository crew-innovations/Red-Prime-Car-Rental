<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LeadSourceSeeder extends Seeder
{
    /** Lead sources from Schedule A2. */
    public const SOURCES = [
        'Instagram', 'Facebook', 'TikTok', 'Snapchat', 'Website', 'WhatsApp',
        'Google Ads / Search', 'Phone', 'Concierge / Hotel', 'Travel Agency',
        'Referral', 'Existing Customer', 'Walk-in', 'Corporate Partner', 'Other',
    ];

    public function run(): void
    {
        foreach (self::SOURCES as $i => $name) {
            LeadSource::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $i + 1, 'is_active' => true],
            );
        }
    }
}
