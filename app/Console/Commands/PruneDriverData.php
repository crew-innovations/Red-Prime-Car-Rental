<?php

namespace App\Console\Commands;

use App\Models\DriverAction;
use App\Models\DriverLocation;
use App\Models\Setting;
use Illuminate\Console\Command;

/** Driver locations are kept for the retention period (brief: 30 days), phone action ids for 30 days. */
class PruneDriverData extends Command
{
    protected $signature = 'crm:prune-driver-data';

    protected $description = 'Remove driver locations older than the retention period';

    public function handle(): int
    {
        $days = max(1, (int) Setting::read('location_retention_days', 30));
        $n = DriverLocation::where('recorded_at', '<', now()->subDays($days))->delete();
        $a = DriverAction::where('created_at', '<', now()->subDays(30))->delete();
        $this->info("{$n} locations and {$a} old phone action ids removed.");

        return self::SUCCESS;
    }
}
