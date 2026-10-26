<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every query of the customer account area starts here, so a customer can only
 * ever reach their own records (changing an id in the address finds nothing).
 */
class CustomerArea
{
    public static function customerId(): int
    {
        $user = auth()->user();
        abort_unless($user && $user->isCustomerAccount(), 403);

        return (int) $user->customer_id;
    }

    public static function customer(): Customer
    {
        return Customer::withTrashed()->findOrFail(self::customerId());
    }

    /** Bookings the customer should see (not internal drafts). */
    public static function bookings(): Builder
    {
        return Booking::query()
            ->where('customer_id', self::customerId())
            ->whereNotIn('status', [BookingStatus::Inquiry, BookingStatus::Quotation]);
    }

    public static function quotations(): Builder
    {
        return Quotation::query()
            ->where('customer_id', self::customerId())
            ->where('status', '!=', QuotationStatus::Draft)
            ->whereNotNull('public_token');
    }

    public static function documents(): Builder
    {
        return CustomerDocument::query()->where('customer_id', self::customerId());
    }

    /** Money received and refunds for the customer's own bookings (no internal balance rows). */
    public static function payments(): Builder
    {
        $id = self::customerId();

        return Transaction::query()
            ->where('is_balance', false)
            ->whereIn('status', [\App\Enums\PaymentStatus::Paid, \App\Enums\PaymentStatus::PartiallyPaid])
            ->where(fn (Builder $q) => $q
                ->whereIn('booking_id', fn ($s) => $s->select('id')->from('bookings')->where('customer_id', $id))
                ->orWhere(fn (Builder $q) => $q->whereNull('booking_id')->where('customer_id', $id)))
            ->where(fn (Builder $q) => $q
                ->where('type', TransactionType::Income)
                ->orWhere(fn (Builder $q) => $q->where('type', TransactionType::Expense)->where('category', 'deposit_refund')));
    }

    public static function allows(string $setting): bool
    {
        return (bool) Setting::read($setting, true);
    }
}
