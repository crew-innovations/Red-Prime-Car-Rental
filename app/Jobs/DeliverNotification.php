<?php

namespace App\Jobs;

use App\Support\Mail\EmailLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Notifications\Notification;

/** One notification to one person, through the queue (4 Nov, 9.4). Tried 3 times, then logged as failed. */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public object $notifiable, public Notification $notification) {}

    public function handle(): void
    {
        $this->notifiable->notifyNow($this->notification);
    }

    public function failed(\Throwable $e): void
    {
        EmailLogger::failed($this->notification->emailLogId ?? null, $e);
        report($e);
    }
}
