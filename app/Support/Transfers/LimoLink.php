<?php

namespace App\Support\Transfers;

use App\Models\Lead;

/**
 * 6 Nov (limousine L4): the booking link an agent sends from a social-media lead. It opens the
 * website limousine page already filled in; a booking made from it joins THAT lead (and so its
 * agent), instead of starting a new lead. The lead id is signed, so it can't be changed by hand.
 */
class LimoLink
{
    public static function ref(Lead $lead): string
    {
        return $lead->id.'.'.substr(hash_hmac('sha256', 'limo-lead:'.$lead->id, (string) config('app.key')), 0, 16);
    }

    public static function lead(?string $ref): ?Lead
    {
        if (! $ref || ! preg_match('/^(\d+)\.([a-f0-9]{16})$/', $ref, $m)) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', 'limo-lead:'.$m[1], (string) config('app.key')), 0, 16);

        return hash_equals($expected, $m[2]) ? Lead::find((int) $m[1]) : null;
    }

    /** The website link, filled in with what the agent priced. */
    public static function url(Lead $lead, array $q): string
    {
        $q = array_filter([
            'service' => $q['service'] ?? null, 'pickup' => $q['pickup'] ?? null, 'dropoff' => $q['dropoff'] ?? null,
            'date' => $q['date'] ?? null, 'time' => $q['time'] ?? null, 'class' => $q['class'] ?? null,
            'pax' => $q['passengers'] ?? null, 'lr' => self::ref($lead),
        ], fn ($v) => $v !== null && $v !== '');

        return route('site.limousine').'?'.http_build_query($q);
    }
}
