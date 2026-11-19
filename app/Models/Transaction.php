<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Transaction extends Model implements HasMedia
{
    use Audited, InteractsWithMedia, SoftDeletes;

    /**
     * Rows that count in totals and reports (28 Oct): not reversed, not an
     * estimate, and approved (or entered by finance, which needs no approval).
     */
    public const COUNTS_SQL = "reversed_at IS NULL AND is_estimate = 0 AND (approval_status IS NULL OR approval_status = 'approved')";

    protected $guarded = ['id'];

    public const INCOME_CATEGORIES = [
        'car_rental' => 'Car rental',
        'yacht_charter' => 'Yacht charter',
        'yacht_trip' => 'Yacht trip tickets',
        'travel_package' => 'Trips & packages',
        'chauffeur' => 'Chauffeur service',
        'transfer' => 'Limousine & transfers',
        'delivery' => 'Delivery & pick-up',
        'extras' => 'Add-ons & extras',
        'deposit' => 'Security deposit held',
        'fines_recovered' => 'Fines / Salik recovered',
        'damage' => 'Damage charges',
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
        'fines_paid' => 'Fines paid for customers',      // 4 Nov (C.2): pass-through
        'tolls_paid' => 'Tolls paid for customers',      // pass-through
        'tolls' => 'Salik / Darb / parking (company)',   // tolls no customer pays
        'toll_topup' => 'Salik / Darb top-up',           // a transfer to the toll account, not a cost
        'other_expense' => 'Other expense',
    ];

    /** Money that is not real revenue (held / returned to the customer). */
    public const NON_REVENUE_CATEGORIES = ['deposit'];

    public const NON_COST_CATEGORIES = ['deposit_refund', 'toll_topup'];

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
            'is_balance' => 'boolean',
            'is_estimate' => 'boolean',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'pass_through' => 'boolean',
            'cheque_date' => 'date', // 19 Nov
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $t) {
            $t->user_id ??= auth()->id();
            // 19 Nov: real money (not the system balance row) is put on the usual account for its method.
            if (! $t->is_balance && ! $t->bank_account && $t->method) {
                $t->bank_account = \App\Support\Accounts\MoneyAccounts::defaultFor($t->method);
            }

            // Expenses from staff without finance rights wait for approval (Business settings).
            if ($t->type === TransactionType::Expense && $t->approval_status === null && auth()->check()
                && ! \App\Support\Access::allows('manage-finance') && (bool) Setting::read('expense_approval_required', true)
                && ! in_array($t->category, self::NON_COST_CATEGORIES, true)) {
                $t->approval_status = 'pending';
            }

            // The same money typed twice within 30 seconds (a double click) is refused.
            if ($t->type === TransactionType::Income && ! $t->is_balance && $t->booking_id && auth()->check()) {
                $twin = static::query()->where('booking_id', $t->booking_id)->where('type', TransactionType::Income)->where('is_balance', false)
                    ->where('amount', round((float) $t->amount, 2))->where('category', $t->category)->whereNull('reversed_at')
                    ->where('method', $t->method instanceof \BackedEnum ? $t->method->value : $t->method)->where('description', $t->description)
                    ->where('user_id', $t->user_id)->where('created_at', '>=', now()->subSeconds(30))
                    ->when($t->reference, fn ($q, $ref) => $q->where('reference', $ref))
                    ->exists();
                if ($twin) {
                    throw ValidationException::withMessages(['data.amount' => 'This payment was just recorded. Check the list before adding it again.']);
                }
            }

            // Payments added on a booking inherit its customer and car / yacht / trip.
            if ($t->booking_id && ($booking = Booking::find($t->booking_id))) {
                $t->customer_id ??= $booking->customer_id;
                $t->related_type ??= $booking->bookable_type;
                $t->related_id ??= $booking->bookable_id;
            }
        });

        // Supplier bills (29 Oct): their paid status follows the supplier payments only, and a bill
        // with payments on it can't be reversed or changed until those payments are reversed.
        static::updating(function (Transaction $t) {
            if (! $t->supplier_id || $t->type !== TransactionType::Expense || \App\Support\Suppliers\SupplierLedger::$writing) {
                return;
            }
            $hasPayments = \App\Support\Suppliers\SupplierLedger::paidOn($t) > 0;
            if ($t->isDirty(['status', 'amount_paid']) && ! $t->isDirty('reversed_at')) {
                throw ValidationException::withMessages(['data.status' => 'Supplier bills are paid from Suppliers › Record payment, so the balance stays right.']);
            }
            if ($hasPayments && ($t->isDirty('reversed_at') || $t->isDirty('amount') || $t->isDirty('supplier_id'))) {
                throw ValidationException::withMessages(['data.amount' => 'Payments are recorded against this bill. Reverse those payments first (Suppliers › payments).']);
            }
        });

        // The system "balance due" row becomes a normal payment once it is paid.
        static::updating(function (Transaction $t) {
            if ($t->is_balance && $t->status === PaymentStatus::Paid) {
                $t->is_balance = false;
                $t->date = today();
                $t->description = 'Payment · '.($t->booking?->reference ?? 'booking');
            }
        });

        $sync = fn (Transaction $t) => $t->booking_id ? $t->booking()->first()?->syncBalance() : null;
        static::saved($sync);
        // 4 Nov (brief 9.3): payment received / deposit refunded.
        static::saved(fn (Transaction $t) => rescue(fn () => \App\Support\Notifications\EventHooks::transaction($t)));
        static::deleted($sync);
        static::restored($sync);
    }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Supplier::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipts')->useDisk('private');
    }

    public function counts(): bool
    {
        return ! $this->reversed_at && ! $this->is_estimate && in_array($this->approval_status, [null, 'approved'], true);
    }

    /** Corrections keep the row: it is marked reversed and leaves every total. */
    public function reverse(string $reason): void
    {
        if ($this->reversed_at) {
            return;
        }
        if ($this->is_balance) {
            throw ValidationException::withMessages(['reason' => 'The balance row follows the booking. Change the booking instead.']);
        }
        $this->update(['reversed_at' => now(), 'reversed_by' => auth()->id(), 'reversal_reason' => mb_substr($reason, 0, 255)]);
    }

    public function approve(): void
    {
        $this->update(['approval_status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
    }

    public function reject(string $reason): void
    {
        $this->update(['approval_status' => 'rejected', 'approved_by' => auth()->id(), 'approved_at' => now(), 'notes' => trim(($this->notes ? $this->notes."\n" : '').'Rejected: '.$reason)]);
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function scopeCounting(Builder $query): Builder
    {
        return $query->whereRaw(self::COUNTS_SQL);
    }

    public static function categories(TransactionType|string|null $type = null): array
    {
        $type = $type instanceof TransactionType ? $type->value : $type;

        // Editable under Business settings; the constants above are the starting list.
        $income = \App\Support\Options::categories('income', self::INCOME_CATEGORIES);
        $expense = \App\Support\Options::categories('expense', self::EXPENSE_CATEGORIES);

        return match ($type) {
            TransactionType::Income->value => $income,
            TransactionType::Expense->value => $expense,
            default => $income + $expense,
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

        return "SUM(CASE WHEN type = 'income' AND category NOT IN ({$exclude}) AND ".self::COUNTS_SQL." THEN
            CASE status WHEN 'paid' THEN amount WHEN 'partially_paid' THEN COALESCE(amount_paid, 0) ELSE 0 END
        ELSE 0 END)";
    }

    /** Income still to be collected. */
    public static function outstandingSql(): string
    {
        return "SUM(CASE WHEN type = 'income' AND ".self::COUNTS_SQL." THEN
            CASE status WHEN 'pending' THEN amount WHEN 'overdue' THEN amount
                WHEN 'partially_paid' THEN amount - COALESCE(amount_paid, 0) ELSE 0 END
        ELSE 0 END)";
    }

    /** Costs: every non-refunded expense except deposit refunds. */
    public static function expensesSql(): string
    {
        $exclude = "'".implode("','", self::NON_COST_CATEGORIES)."'";

        return "SUM(CASE WHEN type = 'expense' AND status <> 'refunded' AND category NOT IN ({$exclude}) AND ".self::COUNTS_SQL." THEN amount ELSE 0 END)";
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
