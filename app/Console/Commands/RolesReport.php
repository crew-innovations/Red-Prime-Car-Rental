<?php

namespace App\Console\Commands;

use App\Support\Roles\RoleMigration;
use Illuminate\Console\Command;

/** 5 Nov (four roles): who had which role before the move and what they have now. */
class RolesReport extends Command
{
    protected $signature = 'crm:roles-report';

    protected $description = 'List every staff login with its roles before and after the move to four roles';

    public function handle(): int
    {
        $rows = RoleMigration::report();
        $this->table(['Name', 'Email', 'Before', 'Now', 'Hidden', 'Active'], array_map(fn ($r) => [
            $r['name'], $r['email'], $r['before'], $r['now'], $r['hidden'] ? 'yes' : '', $r['active'] ? 'yes' : 'no',
        ], $rows));

        return self::SUCCESS;
    }
}
