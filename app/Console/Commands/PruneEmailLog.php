<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Models\Setting;
use Illuminate\Console\Command;

/** Email bodies are kept for the retention period (default 90 days), then cleared; the log line stays. */
class PruneEmailLog extends Command
{
    protected $signature = 'crm:prune-email-log';

    protected $description = 'Clear email bodies older than the retention period';

    public function handle(): int
    {
        $days = max(1, (int) Setting::read('email_log_retention_days', 90));
        $n = EmailLog::query()->whereNotNull('body')->where('created_at', '<', now()->subDays($days))->update(['body' => null]);
        EmailLog::query()->whereNotNull('payload')->whereNotIn('status', ['held'])->where('created_at', '<', now()->subDays($days))->update(['payload' => null]);
        $old = \Illuminate\Support\Facades\DB::table('notification_log')->where('created_at', '<', now()->subDays(max(90, $days)))->delete();
        $this->info("{$n} email bodies cleared, {$old} old notification records removed.");

        return self::SUCCESS;
    }
}
