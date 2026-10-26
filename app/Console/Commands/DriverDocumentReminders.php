<?php

namespace App\Console\Commands;

use App\Models\DriverDocument;
use App\Models\Setting;
use App\Support\Drivers\Notify;
use Illuminate\Console\Command;

/**
 * Daily: tell drivers and the people who manage drivers about documents that
 * expire soon or have expired. Each document is mentioned once per stage.
 */
class DriverDocumentReminders extends Command
{
    protected $signature = 'crm:driver-document-reminders';

    protected $description = 'Remind about driver documents that expire soon or have expired';

    public function handle(): int
    {
        if (! Setting::read('driver_doc_reminders', true)) {
            $this->info('Reminders are switched off in Business settings.');

            return self::SUCCESS;
        }

        $soon = today()->addDays(DriverDocument::warnDays());
        $docs = DriverDocument::current()
            ->with('user')
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', $soon)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->get();

        $sent = 0;
        foreach ($docs as $doc) {
            $expired = $doc->expires_on->lt(today());
            $column = $expired ? 'reminded_expired_at' : 'reminded_soon_at';
            if ($doc->{$column}) {
                continue;
            }

            $title = ($expired ? 'Expired: ' : 'Expires soon: ').$doc->typeLabel().' of '.$doc->user->name;
            $url = \App\Filament\Resources\DriverResource::getUrl('edit', ['record' => $doc->user_id]);
            Notify::staff('manage-drivers', $title, $doc->expiryLabel(), $url, null, $expired ? 'danger' : 'warning');
            Notify::driver($doc->user, ($expired ? 'Your ' : 'Renew soon: your ').$doc->typeLabel().($expired ? ' has expired' : ''), $doc->expiryLabel().'. Please send the new copy to the office.', null, $expired ? 'danger' : 'warning');

            $doc->forceFill([$column => now()])->saveQuietly();
            $sent++;
        }

        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
