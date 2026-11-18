<?php

namespace App\Support\Pricing;

use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Quotation;
use App\Models\Setting;
use App\Support\Access;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Advance to confirm (upgrade brief 4 Nov, section 11).
 *
 * The advance is a % of the price (total with VAT, without the security deposit),
 * set per service in Business settings and changeable per quotation / booking.
 * Going below the minimum % needs "Approve prices" and a reason.
 * Bookings: Booking::syncBalance() works out the amount and whether it is reached.
 */
class Advance
{
    public const DUE_RULES = [
        'standard' => 'Cars on return, other services at the start (as before)',
        'before_start' => 'Before the start (hours below)',
        'on_delivery' => 'On delivery / at the start',
        'on_return' => 'On return / at the end',
    ];

    /** Default % for a service; null = no advance rule for it. */
    public static function defaultPercent(ServiceType|string|null $service): ?float
    {
        $key = $service instanceof ServiceType ? $service->value : $service;
        $all = (array) Setting::read('advance_percent', []);
        $v = $key !== null ? ($all[$key] ?? null) : null;

        return $v === null || $v === '' ? null : max(0.0, min(100.0, (float) $v));
    }

    public static function minPercent(): ?float
    {
        $v = Setting::read('advance_min_percent');

        return $v === null || $v === '' ? null : (float) $v;
    }

    public static function confirmsBooking(): bool
    {
        return (bool) Setting::read('confirm_on_advance', true);
    }

    public static function amount(float $total, ?float $percent): ?float
    {
        return $percent === null ? null : round(max(0, $total) * $percent / 100, 2);
    }

    /**
     * On save of a quotation or booking: fill the default % for a new record, refuse a % below
     * the minimum without "Approve prices" and a reason. Returns the % to use (null = none).
     */
    public static function prepare(Quotation|Booking $record): ?float
    {
        if (! $record->exists && $record->advance_percent === null && ! ($record instanceof Quotation && (float) $record->advance_required > 0)) {
            $record->advance_percent = static::defaultPercent($record->service_type);
        }
        $p = $record->advance_percent;
        if ($p === null || $p === '') {
            $record->advance_percent = null;

            return null;
        }
        $p = max(0.0, min(100.0, (float) $p));
        $record->advance_percent = $p;

        $min = static::minPercent();
        $changed = ! $record->exists || $record->isDirty('advance_percent');
        if ($min !== null && $p < $min - 0.001 && $changed && auth()->check()) {
            if (! Access::allows('approve-prices')) {
                throw ValidationException::withMessages(['data.advance_percent' => 'The advance can\'t be below '.Discounts::percentText($min).'. A manager can allow less.']);
            }
            if (blank($record->advance_reason)) {
                throw ValidationException::withMessages(['data.advance_reason' => 'Say why the advance is below '.Discounts::percentText($min).'.']);
            }
        }

        return $p;
    }

    /** When the balance (after the advance) is due, by the Business settings rule. */
    public static function balanceDue(Model $record): ?Carbon
    {
        $start = $record->start_at;
        $end = $record->end_at;
        $isCar = $record->service_type === ServiceType::Car;

        $due = match ((string) Setting::read('balance_due_rule', 'standard')) {
            'before_start' => $start?->copy()->subHours(max(0, (int) Setting::read('balance_due_hours', 48))),
            'on_delivery' => $start?->copy(),
            'on_return' => ($end ?? $start)?->copy(),
            default => ($isCar ? $end : $start)?->copy(),
        };

        return $due?->startOfDay();
    }

    /** "Advance to confirm: 30% = AED 900.00 · Balance AED 2,100.00 due 12 Nov 2026" */
    public static function line(Model $record): ?string
    {
        $required = (float) $record->advance_required;
        if ($required <= 0) {
            return null;
        }
        $total = (float) $record->total;
        $due = static::balanceDue($record);
        $text = 'Advance to confirm: '.($record->advance_percent !== null ? Discounts::percentText((float) $record->advance_percent).' = ' : '')
            .'AED '.number_format($required, 2);
        if ($total - $required > 0.005) {
            $text .= ' · Balance AED '.number_format($total - $required, 2).($due ? ' due '.$due->format('d M Y') : '');
        }

        return $text;
    }
}
