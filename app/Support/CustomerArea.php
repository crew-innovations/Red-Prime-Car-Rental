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

    /* ---------- 19 Nov: documents first (Bilal: "redirect to the document upload screen; access only after
       all required documents are uploaded and approved; no separate upload link") ---------- */

    /** On in Business settings › Customer accounts (on by default; the required types come from Business settings › Documents). */
    public static function documentsGateOn(): bool
    {
        return (bool) Setting::read('account_documents_gate', true);
    }

    /**
     * Document types the customer needs for their open bookings and quotations (the same rules staff see
     * on the booking), as type => [label, status text, ok]. Empty when nothing is required.
     */
    public static function requiredDocuments(): array
    {
        $id = self::customerId();
        $cache = request()->attributes->get('rp.required_docs');
        if (is_array($cache) && ($cache['id'] ?? null) === $id) {
            return $cache['list'];
        }
        $list = self::requiredDocumentsFor(self::customer());
        request()->attributes->set('rp.required_docs', ['id' => $id, 'list' => $list]);

        return $list;
    }

    /** The same list for any customer (staff side, e.g. to tell the customer their account is ready). */
    public static function requiredDocumentsFor(Customer $customer): array
    {
        $id = $customer->getKey();
        $types = Booking::query()->where('customer_id', $id)
            ->whereIn('status', [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress])
            ->with('customer')->get()
            ->flatMap(fn (Booking $b) => $b->requiredDocumentTypes());
        $rules = (array) Setting::read('doc_requirements', []);
        Quotation::query()->where('customer_id', $id)
            ->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation, QuotationStatus::Accepted])
            ->get()
            ->each(function (Quotation $q) use (&$types, $rules, $customer) {
                foreach (Booking::documentKindsFor($q->service_type, $q->drive_mode, $customer, $q->crew_mode) as $kind) {
                    $types = $types->merge((array) ($rules[$kind] ?? []));
                }
            });

        return Booking::checklistFor($id, $types->filter()->unique()->values()->all());
    }

    /** True when the customer may use the whole account (gate off, or every required document approved). */
    public static function unlocked(): bool
    {
        if (! self::documentsGateOn()) {
            return true;
        }

        return collect(self::requiredDocuments())->every(fn (array $row) => $row[2]);
    }

    /** Required types not approved yet, type => label (for the upload form and the email). */
    public static function documentsStillNeeded(): array
    {
        return collect(self::requiredDocuments())->reject(fn (array $row) => $row[2])->map(fn (array $row) => $row[0])->all();
    }

    public static function allows(string $setting): bool
    {
        return (bool) Setting::read($setting, true);
    }
}
