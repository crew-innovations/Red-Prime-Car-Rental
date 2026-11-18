<?php

namespace App\Console\Commands;

use App\Support\Mail\Announcements;
use Illuminate\Console\Command;

/** Sends announcement emails whose turn has come, within each mailbox's hourly limit (4 Nov, 14.3). */
class SendAnnouncements extends Command
{
    protected $signature = 'crm:send-announcements {--max=25}';

    protected $description = 'Send waiting announcement emails within the hourly limit';

    public function handle(): int
    {
        $n = Announcements::sendDue(max(1, (int) $this->option('max')));
        $this->info("{$n} announcement emails sent.");

        return self::SUCCESS;
    }
}
