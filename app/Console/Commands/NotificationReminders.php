<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\QuotationResource;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Setting;
use App\Support\Drivers\DriverJobs;
use App\Support\Drivers\Notify;
use App\Support\Pricing\Advance;
use App\Support\Rules;
use Illuminate\Console\Command;

/**
 * Time-based notices of brief 9.3 (4 Nov, Phase 2), every 5 minutes from the scheduler:
 * lead not accepted, quotation expiring, balance due soon / overdue, job starting soon,
 * late return. Each is sent once (dedupe key), so running it again sends nothing new.
 */
class NotificationReminders extends Command
{
    protected $signature = 'crm:notification-reminders';

    protected $description = 'Send time-based reminders (leads, quotations, balances, jobs, late returns)';

    public function handle(): int
    {
        $n = 0;
        $n += $this->leads();
        $n += \App\Support\Leads\LeadBroadcast::remindWaiting(); // 5 Nov (S3): leads no agent accepted in time
        $n += $this->quotes();
        $n += \App\Support\FollowUps\FollowUps::run(); // 12 Nov: customer has not answered a quotation / price / payment link
        $n += $this->balances();
        $n += $this->jobs();
        $n += $this->late();
        $n += \App\Support\Drivers\DriverFlow::remindUnaccepted(); // 4 Nov (15): new jobs not accepted in time
        $this->info($n.' reminder(s) checked.');

        return self::SUCCESS;
    }

    protected function leads(): int
    {
        $minutes = (int) Setting::read('lead_accept_minutes', 60);
        if ($minutes <= 0) {
            return 0;
        }
        $n = 0;
        Lead::query()->where('assignment_status', 'pending')->whereNotNull('assigned_to')->where('assigned_at', '<=', now()->subMinutes($minutes))
            ->with('assignee')->limit(200)->get()->each(function (Lead $lead) use (&$n) {
                $url = LeadResource::getUrl('edit', ['record' => $lead], panel: 'admin');
                $key = 'lead_unaccepted:lead:'.$lead->id.':'.$lead->assigned_at?->timestamp;
                Notify::person($lead->assignee, 'Please accept this lead: '.$lead->name, 'Waiting since '.$lead->assigned_at?->format('H:i').'.', $url, 'warning', 'lead_unaccepted', $key);
                Notify::staff('assign-leads', 'Lead not accepted: '.$lead->name, ($lead->assignee?->name ?? 'The agent').' has not accepted it yet.', $url, null, 'warning', 'lead_unaccepted', $key);
                $n++;
            });

        return $n;
    }

    protected function quotes(): int
    {
        $n = 0;
        Quotation::query()->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation])
            ->whereDate('valid_until', today()->addDay())->with(['creator', 'lead.assignee', 'customer'])->limit(300)->get()
            ->each(function (Quotation $q) use (&$n) {
                $key = 'quote_expiring:quotation:'.$q->id;
                Notify::person($q->lead?->assignee ?? $q->creator, 'Quotation '.$q->number.' expires tomorrow', ($q->customerName() ?? 'Customer').' has not answered yet.',
                    QuotationResource::getUrl('edit', ['record' => $q], panel: 'admin'), 'warning', 'quote_expiring', $key);
                if (Setting::read('quote_expiry_customer_reminder')) {
                    Notify::customer($q->customer, 'Your quotation '.$q->number.' expires tomorrow', 'View and accept it before it expires.', $q->publicUrl(), 'quote_expiring', $q, $key);
                }
                $n++;
            });

        return $n;
    }

    protected function balances(): int
    {
        $days = (int) Setting::read('balance_reminder_days', 2);
        $n = 0;
        Booking::query()->whereIn('status', [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress])
            ->where('balance', '>', 0)->where('start_at', '<=', now()->addDays(max(1, $days) + 31))->with(['customer', 'assignee'])->limit(500)->get()
            ->each(function (Booking $b) use ($days, &$n) {
                $due = Advance::balanceDue($b);
                if (! $due || $due->gt(today()->addDays($days))) {
                    return;
                }
                $overdue = $due->lt(today());
                $key = ($overdue ? 'balance_overdue' : 'balance_due').':booking:'.$b->id.':'.$due->toDateString();
                $text = 'AED '.number_format((float) $b->balance, 2).($overdue ? ' was due ' : ' due ').$due->format('d M Y').'.';
                Notify::team('manage-finance', ($overdue ? 'Balance overdue: ' : 'Balance due soon: ').$b->reference, $text,
                    BookingResource::getUrl('edit', ['record' => $b], panel: 'admin'), $b->assignee, $overdue ? 'danger' : 'warning', 'balance_due', $key);
                Notify::customer($b->customer, ($overdue ? 'Balance overdue for ' : 'Balance due soon for ').$b->reference, $text.' You can pay online from your account.',
                    url('/account/bookings/'.$b->id), 'balance_due', $b, $key, $overdue ? 'danger' : 'warning');
                $n++;
            });

        return $n;
    }

    protected function jobs(): int
    {
        $minutes = (int) Setting::read('job_soon_minutes', 60);
        if ($minutes <= 0) {
            return 0;
        }
        $n = 0;
        BookingJob::query()->open()->whereNotNull('driver_id')->whereBetween('scheduled_at', [now(), now()->addMinutes($minutes)])
            ->with(['driver', 'booking'])->limit(200)->get()->each(function (BookingJob $job) use (&$n) {
                Notify::driver($job->driver, 'Starts at '.$job->scheduled_at->format('H:i').': '.$job->typeLabel(), ($job->booking?->reference ?? '').($job->booking?->bookable ? ' · '.$job->booking->bookable->name : ''),
                    DriverJobs::url($job), 'warning', 'job_soon', 'job_soon:job:'.$job->id.':'.$job->scheduled_at->timestamp);
                $n++;
            });

        return $n;
    }

    protected function late(): int
    {
        $n = 0;
        Booking::query()->where('status', BookingStatus::InProgress)->where('end_at', '<', now()->subMinutes(Rules::lateGraceMinutes()))
            ->with(['customer', 'assignee'])->limit(200)->get()->each(function (Booking $b) use (&$n) {
                $key = 'late_return:booking:'.$b->id.':'.today()->toDateString();
                $text = 'Due back '.$b->end_at->format('D d M, H:i').'.';
                Notify::team('operate-fleet', 'Late return: '.$b->reference, $text.' '.$b->title(), BookingResource::getUrl('edit', ['record' => $b], panel: 'admin'), $b->assignee, 'danger', 'late_return', $key);
                Notify::staff('assign-leads', 'Late return: '.$b->reference, $text.' '.$b->title(), BookingResource::getUrl('edit', ['record' => $b], panel: 'admin'), null, 'danger', 'late_return', $key);
                if (Setting::read('late_return_customer_reminder', true)) {
                    Notify::customer($b->customer, 'Your '.($b->isCar() ? 'car' : $b->serviceNoun()).' was due back', $text.' Please contact us if you need more time.', url('/account/bookings/'.$b->id), 'late_return', $b, $key, 'warning');
                }
                $n++;
            });

        return $n;
    }
}
