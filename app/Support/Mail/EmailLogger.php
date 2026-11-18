<?php

namespace App\Support\Mail;

use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The email log (4 Nov, 14.4). Rows are made when an email is queued (Notifier / Outbox),
 * or when any other email starts sending, and follow it to "sent" or "failed".
 */
class EmailLogger
{
    public const HEADER = 'X-RP-Email-Log';

    /** A queued email; returns the log id the email carries in a header. */
    public static function queued(string $toEmail, ?string $toName, string $type, string $kind, ?Model $related = null, ?User $recipientUser = null, ?string $batch = null): int
    {
        return EmailLog::create([
            'to_email' => mb_substr($toEmail, 0, 190),
            'to_name' => $toName ? mb_substr($toName, 0, 190) : null,
            'type' => $type,
            'kind' => mb_substr($kind, 0, 80),
            'status' => 'queued',
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'user_id' => $recipientUser?->id,
            'customer_id' => $recipientUser?->customer_id ?? ($related instanceof Customer ? $related->id : self::customerFor($toEmail)),
            'triggered_by' => auth()->id(),
            'batch' => $batch,
        ])->id;
    }

    public static function sending(MessageSending $event): void
    {
        rescue(function () use ($event) {
            $msg = $event->message;
            if (! $msg instanceof Email) {
                return;
            }
            $headers = $msg->getHeaders();
            $id = $headers->has(self::HEADER) ? (int) $headers->get(self::HEADER)->getBodyAsString() : null;
            $to = $msg->getTo()[0] ?? null;
            $row = $id ? EmailLog::find($id) : null;
            if (! $row) {
                $email = $to instanceof Address ? $to->getAddress() : '';
                $user = $email ? User::where('email', $email)->first() : null;
                $row = EmailLog::create([
                    'to_email' => mb_substr($email ?: 'unknown', 0, 190),
                    'to_name' => $to instanceof Address ? mb_substr($to->getName(), 0, 190) : null,
                    'type' => $headers->has('X-RP-Mail-Type') ? $headers->get('X-RP-Mail-Type')->getBodyAsString() : 'other',
                    'kind' => 'email',
                    'status' => 'sending',
                    'user_id' => $user?->id,
                    'customer_id' => $user?->customer_id ?? self::customerFor($email),
                    'triggered_by' => auth()->id(),
                ]);
                $headers->addTextHeader(self::HEADER, (string) $row->id);
            }
            $account = $headers->has('X-RP-Mail-Account') ? (int) $headers->get('X-RP-Mail-Account')->getBodyAsString() : null;
            $row->forceFill([
                'status' => 'sending',
                'subject' => mb_substr((string) $msg->getSubject(), 0, 255),
                'body' => $msg->getHtmlBody() ?? $msg->getTextBody(),
                'mail_account_id' => $account ?: $row->mail_account_id,
                'attempts' => $row->attempts + 1,
                'error' => null,
            ])->save();
        }, report: false);
    }

    public static function sent(MessageSent $event): void
    {
        rescue(function () use ($event) {
            $msg = $event->sent->getOriginalMessage();
            if (! $msg instanceof Email || ! $msg->getHeaders()->has(self::HEADER)) {
                return;
            }
            EmailLog::whereKey((int) $msg->getHeaders()->get(self::HEADER)->getBodyAsString())->update([
                'status' => 'sent', 'sent_at' => now(), 'message_id' => mb_substr((string) $event->sent->getMessageId(), 0, 190), 'error' => null,
            ]);
        }, report: false);
    }

    public static function failed(?int $id, \Throwable $e): void
    {
        if ($id) {
            EmailLog::whereKey($id)->update(['status' => 'failed', 'error' => mb_substr(MailRouter::cleanError($e->getMessage()), 0, 2000)]);
        }
    }

    protected static function customerFor(?string $email): ?int
    {
        return $email ? Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->value('id') : null;
    }
}
