<?php

namespace App\Console\Commands;

use App\Support\Contracts\ContractInvoices;
use Illuminate\Console\Command;

/** 18 Nov: monthly contract invoices, contract-ending reminders and automatic renewals (daily). */
class ContractTasks extends Command
{
    protected $signature = 'crm:contract-tasks';

    protected $description = 'Rental contracts: issue period invoices that are due, remind about contracts ending soon, renew the ones set to renew.';

    public function handle(): int
    {
        $done = ContractInvoices::runDaily();
        $this->info("Invoices issued: {$done['invoices']} · reminders: {$done['reminders']} · renewed: {$done['renewed']}");

        return self::SUCCESS;
    }
}
