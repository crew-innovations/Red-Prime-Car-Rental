<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Removes audit log lines older than "Keep the audit log for" (Business settings).
 * Empty setting = keep everything (the default). The removal itself is logged.
 */
class PruneAuditLog extends Command
{
    protected $signature = 'crm:prune-audit-log';

    protected $description = 'Remove audit log lines older than the retention set in Business settings';

    public function handle(): int
    {
        $days = (int) Setting::read('audit_retention_days');
        if ($days < 90) {
            $this->info($days ? 'Retention below 90 days is not allowed; nothing removed.' : 'Retention not set: everything is kept.');

            return self::SUCCESS;
        }

        // A query delete, not model deletes: lines cannot be removed one by one from the app.
        $removed = AuditLog::query()->where('created_at', '<', now()->subDays($days))->toBase()->delete();
        AuditLog::event('settings', "Audit log lines older than {$days} days removed: {$removed}", ['removed' => $removed, 'days' => $days]);
        $this->info("Removed {$removed} line(s).");

        return self::SUCCESS;
    }
}
