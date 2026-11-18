<?php

namespace App\Support\Chat;

use App\Models\ChatThread;
use App\Models\Setting;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Carbon;

/**
 * 12 Nov (C4, Bilal: "c4 continue"): what the CRM answers by itself in a conversation from
 * Instagram / Messenger / WhatsApp.
 *
 *  - Welcome: once, when a new enquiry starts (a new conversation, or the same person writing
 *    again after their lead was won or lost).
 *  - Closed: outside the working hours set in Settings, instead of the welcome on a new enquiry,
 *    and at most once every 12 hours on later messages. Off until the hours are set.
 *
 * Both texts are edited in Settings › Chat auto replies. {name} is the customer's first name,
 * {company} the company name. The welcome can end with the details form link (the form then
 * completes the same lead).
 */
class AutoReply
{
    public const WELCOME = 'Hello {name}! Thank you for contacting {company}. One of our team will reply to you here shortly.';

    public const CLOSED = 'Hello {name}! Thank you for your message. Our team is not online right now. We will reply here as soon as we are back.';

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public static function welcomeOn(): bool
    {
        return (bool) Setting::read('chat_welcome_enabled', true);
    }

    /** The closed reply needs the hours: without them the CRM cannot know when the team is away. */
    public static function closedOn(): bool
    {
        return (bool) Setting::read('chat_closed_enabled', false) && self::hours() !== null;
    }

    /** @return array{0: int, 1: int}|null opening and closing hour (Dubai time) */
    public static function hours(): ?array
    {
        $from = Setting::read('chat_hours_from');
        $to = Setting::read('chat_hours_to');

        return is_numeric($from) && is_numeric($to) && (int) $from !== (int) $to ? [(int) $from, (int) $to] : null;
    }

    /** @return list<int> ISO week days the team works (empty setting = every day) */
    public static function days(): array
    {
        $days = collect((array) Setting::read('chat_days'))->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 7)->values()->all();

        return $days ?: array_keys(self::DAYS);
    }

    /** Is the team at work now? True when no hours are set. */
    public static function open(?Carbon $at = null): bool
    {
        $hours = self::hours();
        if (! $hours) {
            return true;
        }
        $at ??= now();
        [$from, $to] = $hours;
        $h = (int) $at->format('G');
        // Hours past midnight (e.g. 10 → 2) belong to the day they started on.
        $day = $from > $to && $h < $to ? (int) $at->copy()->subDay()->format('N') : (int) $at->format('N');
        $inHours = $from < $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to);

        return $inHours && in_array($day, self::days(), true);
    }

    /** Called after each incoming message. */
    public static function after(ChatThread $t, bool $newEnquiry): ?string
    {
        if (! $t->isSocial()) {
            return null;
        }
        if (self::closedOn() && ! self::open()) {
            // One "closed" reply per 12 hours and conversation, however many messages arrive.
            if (! Notifier::claim('chat-closed:'.$t->id.':'.now()->format('Y-m-d').':'.((int) now()->format('G') < 12 ? 'a' : 'p'), $t, 'message')) {
                return null;
            }

            return Social::system($t, self::text($t, (string) (Setting::read('chat_closed_text') ?: self::CLOSED), $newEnquiry)) ? 'closed' : null;
        }
        if ($newEnquiry && self::welcomeOn()) {
            return Social::system($t, self::text($t, (string) (Setting::read('chat_welcome_text') ?: self::WELCOME), true)) ? 'welcome' : null;
        }

        return null;
    }

    /** The text with the customer's name, and the form link on a new enquiry when that is switched on. */
    public static function text(ChatThread $t, string $template, bool $withForm): string
    {
        $name = trim((string) ($t->external_name ?: $t->lead?->name));
        $first = $name !== '' && ! str_starts_with($name, '@') && ! str_ends_with($name, ' user') ? strtok($name, ' ') : '';
        $text = str_replace(['{name}', '{company}'], [$first, (string) Setting::read('company_name', 'RED PRIME RENTALS')], $template);
        $text = trim(preg_replace(['/ +([!,.?])/', '/  +/'], ['$1', ' '], $text)); // "Hello !" when there is no name
        if ($withForm && Setting::read('chat_welcome_form', true) && ($form = Social::links($t)['form'] ?? null)) {
            $text .= "\n\n".$form['text'];
        }

        return mb_substr($text, 0, 1900);
    }
}
