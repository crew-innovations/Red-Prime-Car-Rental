<?php

namespace App\Support\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\User;
use App\Notifications\Concerns\RoutedMail;
use App\Support\Mail\EmailLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Sends notifications (4 Nov, brief 9.4):
 *  - one event, one notification per person (dedupe key);
 *  - email rows in the email log from the start ("queued");
 *  - through the queue when the worker runs, else straight after the database commit;
 *  - never throws: a failed email or push never stops the work that triggered it.
 */
class Notifier
{
    /** @return int how many people it went to */
    public static function send(mixed $recipients, Notification $notification, ?Model $related = null, ?string $dedupe = null): int
    {
        $count = 0;
        foreach (self::list($recipients) as $to) {
            try {
                if ($dedupe && ! self::claim($dedupe, $to, $notification->event ?? 'other')) {
                    continue;
                }
                $copy = clone $notification;
                if (method_exists($copy, 'prepareFor')) {
                    $copy->prepareFor($to);
                }
                $channels = (array) $copy->via($to);
                if ($channels === []) {
                    continue;
                }
                if (in_array('mail', $channels, true) && in_array(RoutedMail::class, class_uses_recursive($copy), true)) {
                    [$email, $name] = self::mailTarget($to, $copy);
                    if ($email) {
                        $copy->emailLogId = EmailLogger::queued($email, $name, $copy->mailType($to), $copy->emailKind(), $related, $to instanceof User ? $to : null);
                    }
                }
                self::dispatch($to, $copy);
                $count++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $count;
    }

    /** Email-only to an address (no login), e.g. a customer without an account. */
    public static function mail(string $email, Notification $notification, ?Model $related = null, ?string $dedupe = null): int
    {
        return self::send((new AnonymousNotifiable)->route('mail', $email), $notification, $related, $dedupe);
    }

    public static function dispatch(object $to, Notification $n): void
    {
        if (QueueHealth::workerRunning()) {
            DeliverNotification::dispatch($to, $n)->afterCommit();

            return;
        }
        DB::afterCommit(fn () => self::deliverNow($to, $n));
    }

    public static function deliverNow(object $to, Notification $n): void
    {
        try {
            $to->notifyNow($n);
        } catch (\Throwable $e) {
            EmailLogger::failed($n->emailLogId ?? null, $e);
            report($e);
        }
    }

    /** Records "this person was told about this"; false if they already were. */
    public static function claim(string $key, object $to, string $event = 'other'): bool
    {
        return DB::table('notification_log')->insertOrIgnore([
            'event' => mb_substr($event, 0, 40),
            'dedupe_key' => mb_substr($key, 0, 191),
            'recipient' => self::recipientKey($to),
            'created_at' => now(),
        ]) > 0;
    }

    protected static function recipientKey(object $to): string
    {
        if ($to instanceof Model) {
            return $to->getMorphClass().':'.$to->getKey();
        }
        $mail = $to instanceof AnonymousNotifiable ? ($to->routes['mail'] ?? '') : '';

        return 'mail:'.substr(hash('sha256', mb_strtolower(is_array($mail) ? (string) array_key_first($mail) : (string) $mail)), 0, 40);
    }

    /** @return array{0: ?string, 1: ?string} */
    protected static function mailTarget(object $to, Notification $n): array
    {
        $route = $to->routeNotificationFor('mail', $n);
        if (is_array($route)) {
            $email = array_key_first($route);
            $name = is_string($email) ? $route[$email] : null;
            $email = is_string($email) ? $email : (string) reset($route);

            return [$email ?: null, $name];
        }

        return [$route ? (string) $route : null, $to->name ?? null];
    }

    /** @return iterable<object> */
    protected static function list(mixed $recipients): iterable
    {
        if ($recipients === null) {
            return [];
        }
        if (is_iterable($recipients)) {
            return collect($recipients)->filter()->unique(fn ($r) => $r instanceof Model ? $r->getMorphClass().$r->getKey() : spl_object_id($r))->all();
        }

        return [$recipients];
    }
}
