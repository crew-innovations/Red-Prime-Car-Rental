<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles\RoleMigration;
use Illuminate\Console\Command;

/**
 * 5 Nov (four roles, decision H-34): marks the one hidden technical Super Admin for Crew Innovations
 * and makes every other Super Admin an Admin. Backed up; undone by rolling back the migration.
 */
class SystemAccount extends Command
{
    protected $signature = 'crm:system-account {email : the login that stays the hidden Super Admin} {--force : do not ask}';

    protected $description = 'Mark the hidden technical account and move other Super Admins to Admin';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->whereNull('customer_id')->first();
        if (! $user) {
            $this->error('No staff login with that email.');

            return self::FAILURE;
        }
        $others = User::query()->whereKeyNot($user->id)->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->pluck('email')->all();
        $this->line($user->email.' becomes the hidden Super Admin.');
        $this->line($others ? 'These become Admin: '.implode(', ', $others) : 'No other Super Admin to move.');
        if (! $this->option('force') && ! $this->confirm('Go ahead?')) {
            return self::FAILURE;
        }

        $moved = RoleMigration::markSystemAccount($user);
        rescue(fn () => AuditLog::event('permissions', 'Hidden technical account set: '.$user->email, ['system' => $user->email, 'moved_to_admin' => $moved]), report: false);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
