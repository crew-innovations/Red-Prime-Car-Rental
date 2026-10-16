<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const INCOME_CATEGORIES = [
        'car_rental' => 'Car rental',
        'yacht_charter' => 'Yacht charter',
        'yacht_trip' => 'Yacht trip tickets',
        'chauffeur' => 'Chauffeur service',
        'delivery' => 'Delivery & pick-up',
        'extras' => 'Add-ons & extras',
        'deposit' => 'Security deposit held',
        'fines_recovered' => 'Fines / Salik recovered',
        'other_income' => 'Other income',
    ];

    public const EXPENSE_CATEGORIES = [
        'fuel' => 'Fuel',
        'maintenance' => 'Maintenance & repairs',
        'insurance' => 'Insurance',
        'registration' => 'Registration & permits',
        'salik_fines' => 'Salik & fines',
        'marina' => 'Marina & berthing',
        'cleaning' => 'Cleaning & valet',
        'salaries' => 'Salaries & commissions',
        'partner_payout' => 'Partner payout',
        'marketing' => 'Marketing & ads',
        'rent' => 'Office & rent',
        'deposit_refund' => 'Deposit refund',
        'other_expense' => 'Other expense',
    ];

    /** Money that is not real revenue (held / returned to the customer). */
    public const NON_REVENUE_CATEGORIES = ['deposit'];

    public const NON_COST_CATEGORIES = ['deposit_refund'];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $t) {
            $t->user_id ??= auth()->id();
        });
    }

    public static function categories(?string $type = null): array
    {
        return match ($type) {
            TransactionType::Income->value => self::INCOME_CATEGORIES,
            TransactionType::Expense->value => self::EXPENSE_CATEGORIES,
            default => self::INCOME_CATEGORIES + self::EXPENSE_CATEGORIES,
        };
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? (string) $this->category;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Income);
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Expense);
    }

    public function scopeBetweenDates(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    /*
     * SQL pieces used by every report so the numbers always agree.
     */

    /** Revenue actually received: paid income + paid part of partially paid income, excluding deposits held. */
    public static function receivedSql(): string
    {
        $exclude = "'".implode("','", self::NON_REVENUE_CATEGORIES)."'";

        return "SUM(CASE WHEN type = 'income' AND category NOT IN ({$exclude}) THEN
            CASE status WHEN 'paid' THEN amount WHEN 'partially_paid' THEN COALESCE(amount_paid, 0) ELSE 0 END
        ELSE 0 END)";
    }

    /** Income still to be collected. */
    public static function outstandingSql(): string
    {
        return "SUM(CASE WHEN type = 'income' THEN
            CASE status WHEN 'pending' THEN amount WHEN 'overdue' THEN amount
                WHEN 'partially_paid' THEN amount - COALESCE(amount_paid, 0) ELSE 0 END
        ELSE 0 END)";
    }

    /** Costs: every non-refunded expense except deposit refunds. */
    public static function expensesSql(): string
    {
        $exclude = "'".implode("','", self::NON_COST_CATEGORIES)."'";

        return "SUM(CASE WHEN type = 'expense' AND status <> 'refunded' AND category NOT IN ({$exclude}) THEN amount ELSE 0 END)";
    }

    /** @return array{received: float, outstanding: float, expenses: float, net: float} */
    public static function totals(Builder $query): array
    {
        $row = (clone $query)
            ->selectRaw(self::receivedSql().' as received, '.self::outstandingSql().' as outstanding, '.self::expensesSql().' as expenses')
            ->toBase()
            ->first();

        $received = (float) ($row->received ?? 0);
        $expenses = (float) ($row->expenses ?? 0);

        return [
            'received' => $received,
            'outstanding' => (float) ($row->outstanding ?? 0),
            'expenses' => $expenses,
            'net' => $received - $expenses,
        ];
    }
}
