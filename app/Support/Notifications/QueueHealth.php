<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\Cache;

/**
 * Is the queue worker running? (4 Nov, Phase 2). The scheduled worker leaves a heartbeat every
 * minute. While there is none (no cron on the server yet, or QUEUE_CONNECTION=sync), emails and
 * pushes are sent straight away instead of being queued, so nothing waits forever.
 */
class QueueHealth
{
    public const KEY = 'crm.queue.heartbeat';

    public static function beat(): void
    {
        Cache::put(self::KEY, now()->getTimestamp(), now()->addMinutes(10));
    }

    public static function workerRunning(): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }
        $at = Cache::get(self::KEY);

        return $at !== null && now()->getTimestamp() - (int) $at < 300;
    }

    public static function lastBeat(): ?\Illuminate\Support\Carbon
    {
        $at = Cache::get(self::KEY);

        return $at ? \Illuminate\Support\Carbon::createFromTimestamp((int) $at) : null;
    }
}
