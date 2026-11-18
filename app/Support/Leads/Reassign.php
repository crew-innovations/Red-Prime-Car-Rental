<?php

namespace App\Support\Leads;

use App\Enums\ActivityType;
use App\Enums\BookingStatus;
use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Support\Drivers\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 5 Nov (S3, B.4): Admin moves a lead or a customer, with everything still open, to another agent.
 * Open leads, the customer and bookings that are not finished move; completed and cancelled
 * records keep the agent who handled them. Both agents are told; the audit log keeps the reason.
 */
class Reassign
{
    /** @return array{leads: int, bookings: int} what moved */
    public static function chain(Lead|Customer $record, User $to, string $reason): array
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Please say why it moves to another agent.']);
        }
        if (! $to->is_active || ! \App\Support\Access::rolesOf($to)->intersect(['Agent', 'Admin'])->isNotEmpty()) {
            throw ValidationException::withMessages(['to' => 'Choose an active agent.']);
        }

        $customer = $record instanceof Customer ? $record : $record->customer;
        $from = $record->assignee;

        $moved = DB::transaction(function () use ($record, $customer, $to) {
            $leadIds = collect([$record instanceof Lead ? $record->id : null]);
            if ($customer) {
                $leadIds = $leadIds->merge(Lead::query()->where('customer_id', $customer->id)
                    ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Lost->value])->pluck('id'));
            }
            $leadIds = $leadIds->filter()->unique()->values();
            $leads = Lead::query()->whereIn('id', $leadIds)->update(['assigned_to' => $to->id, 'assignment_status' => 'accepted', 'assigned_at' => now(), 'accepted_at' => now(), 'updated_at' => now()]);

            $bookings = 0;
            if ($customer) {
                $customer->forceFill(['assigned_to' => $to->id])->save();
                $bookings = Booking::query()->where('customer_id', $customer->id)
                    ->whereNotIn('status', [BookingStatus::Completed->value, BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    ->update(['assigned_to' => $to->id, 'updated_at' => now()]);
            }

            return ['leads' => $leads, 'bookings' => $bookings];
        });

        $what = $record instanceof Lead ? 'Lead '.$record->name : 'Customer '.$customer?->name;
        $note = 'Moved to '.$to->name.($from ? ' from '.$from->name : '').': '.$reason;
        if ($record instanceof Lead) {
            $record->activities()->create(['type' => ActivityType::Note, 'user_id' => auth()->id(), 'body' => $note]);
        }
        rescue(fn () => AuditLog::create([
            'user_id' => auth()->id(), 'event' => 'reassign', 'outcome' => 'success',
            'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->getKey(),
            'old_values' => ['agent' => $from?->name], 'new_values' => ['agent' => $to->name, 'reason' => $reason] + $moved,
            'note' => mb_substr($what.': '.$note, 0, 255),
        ]), report: false);

        $url = $record instanceof Lead
            ? \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $record])
            : \App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $customer]);
        DB::afterCommit(function () use ($to, $from, $what, $reason, $url, $record) {
            Notify::person($to, $what.' is yours now', 'Moved to you by Admin: '.$reason, $url, 'info', 'lead_reassigned', 'reassign:'.$record->getMorphClass().':'.$record->getKey().':'.$to->id.':'.now()->timestamp);
            if ($from && ! $from->is($to)) {
                Notify::person($from, $what.' moved to '.$to->name, 'Reason: '.$reason, null, 'warning', 'lead_reassigned', 'reassign-from:'.$record->getMorphClass().':'.$record->getKey().':'.$from->id.':'.now()->timestamp);
            }
        });

        return $moved;
    }
}
