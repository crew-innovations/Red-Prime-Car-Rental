<?php

namespace App\Support\Accounts;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * 19 Nov (suggestion 2): the bank and cash accounts money goes into or comes from (Settings ›
 * Bank & cash accounts), and the daily cash and bank summary.
 */
class MoneyAccounts
{
    /** key => row */
    public static function all(): array
    {
        return collect((array) Setting::read('bank_accounts', []))
            ->filter(fn ($r) => filled($r['key'] ?? null) && filled($r['name'] ?? null))
            ->keyBy('key')->all();
    }

    /** key => "Name (bank)" for a select. */
    public static function options(): array
    {
        return collect(self::all())->map(fn ($r) => $r['name'].(! empty($r['bank']) ? ' · '.$r['bank'] : ''))->all();
    }

    public static function label(?string $key): string
    {
        return self::options()[$key] ?? ($key ? $key : 'Not set');
    }

    /** The account usually used for a method: the first cash account for cash, else the first bank account. */
    public static function defaultFor(PaymentMethod|string|null $method): ?string
    {
        $method = $method instanceof PaymentMethod ? $method->value : $method;
        $rows = collect(self::all());
        $kind = $method === 'cash' ? 'cash' : 'bank';

        return $rows->firstWhere('kind', $kind)['key'] ?? $rows->keys()->first();
    }

    /**
     * Money in and out per account between two dates (paid money only; balance rows and reversed
     * rows left out). Deposits and their refunds are counted: they move real money.
     *
     * @return array<string, array{label: string, in: float, out: float, net: float, count: int}>
     */
    public static function summary(Carbon $from, Carbon $to): array
    {
        $rows = Transaction::query()
            ->where('is_balance', false)->whereNull('reversed_at')
            ->whereIn('status', [PaymentStatus::Paid, PaymentStatus::PartiallyPaid])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['type', 'amount', 'amount_paid', 'status', 'bank_account', 'method']);

        $out = [];
        foreach ($rows as $t) {
            $key = $t->bank_account ?: 'none:'.($t->method?->value ?? 'other');
            $label = $t->bank_account ? self::label($t->bank_account) : 'No account chosen ('.($t->method?->getLabel() ?? 'other').')';
            $amount = $t->status === PaymentStatus::PartiallyPaid ? (float) $t->amount_paid : (float) $t->amount;
            $out[$key] ??= ['label' => $label, 'in' => 0.0, 'out' => 0.0, 'net' => 0.0, 'count' => 0];
            $t->type === TransactionType::Income ? $out[$key]['in'] += $amount : $out[$key]['out'] += $amount;
            $out[$key]['count']++;
        }
        foreach ($out as &$r) {
            $r['in'] = round($r['in'], 2);
            $r['out'] = round($r['out'], 2);
            $r['net'] = round($r['in'] - $r['out'], 2);
        }

        return $out;
    }
}
