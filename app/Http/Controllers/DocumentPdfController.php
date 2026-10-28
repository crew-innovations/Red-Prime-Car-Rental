<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\QuotationResource;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Support\Access;
use App\Support\CustomerArea;
use App\Support\Documents\Pdf;
use Illuminate\Http\Request;

/**
 * Customer documents as PDF (28 Oct, Priority 7): quotation, booking
 * confirmation, invoice / credit note, receipt, return statement.
 * Staff see what their role and agent scope allow; customers only their own
 * records (CustomerArea); a quotation also opens with its secure link.
 */
class DocumentPdfController extends Controller
{
    public function quotation(Request $request, Quotation $quotation)
    {
        $this->allowQuotation($request, $quotation);

        return Pdf::response('pdf.quotation', ['q' => $quotation], 'Quotation '.$quotation->number.'.pdf', $request->boolean('download'));
    }

    /** From the customer's secure link (no login). */
    public function publicQuotation(Request $request, string $token)
    {
        $quotation = Quotation::where('public_token', $token)->first();
        abort_unless($quotation && $quotation->sent_at, 404);

        return Pdf::response('pdf.quotation', ['q' => $quotation], 'Quotation '.$quotation->number.'.pdf', $request->boolean('download'));
    }

    public function confirmation(Request $request, Booking $booking)
    {
        $this->allowBooking($request, $booking);
        abort_unless(in_array($booking->status, \App\Enums\BookingStatus::billable(), true), 404);

        return Pdf::response('pdf.confirmation', ['b' => $booking], 'Booking '.$booking->reference.'.pdf', $request->boolean('download'));
    }

    public function statement(Request $request, Booking $booking)
    {
        $this->allowBooking($request, $booking);
        abort_unless($booking->delivered_at, 404);

        return Pdf::response('pdf.statement', ['b' => $booking], 'Return statement '.$booking->reference.'.pdf', $request->boolean('download'));
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        abort_unless($user, 403);
        if ($user->isCustomerAccount()) {
            abort_unless((int) $invoice->customer_id === CustomerArea::customerId() && $invoice->status === 'issued', 404);
        } else {
            $this->staffOnly();
            abort_unless(Access::allows('record-payments') || Access::allows('manage-finance') || Access::allows('manage-bookings'), 403);
            if ($invoice->booking_id) {
                abort_unless(BookingResource::getEloquentQuery()->whereKey($invoice->booking_id)->exists(), 404);
            }
        }

        return Pdf::response('pdf.invoice', ['i' => $invoice], $invoice->title().' '.$invoice->number.'.pdf', $request->boolean('download'));
    }

    public function receipt(Request $request, Transaction $transaction)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($transaction->type === TransactionType::Income && ! $transaction->is_balance && $transaction->status === PaymentStatus::Paid, 404);
        if ($user->isCustomerAccount()) {
            abort_unless(CustomerArea::payments()->whereKey($transaction->id)->exists(), 404);
        } else {
            $this->staffOnly();
            abort_unless(Access::scopeOwn(Transaction::query(), 'transaction')->whereKey($transaction->id)->exists(), 404);
        }

        return Pdf::response('pdf.receipt', ['t' => $transaction, 'number' => self::receiptNumber($transaction)], 'Receipt '.self::receiptNumber($transaction).'.pdf', $request->boolean('download'));
    }

    public static function receiptNumber(Transaction $t): string
    {
        return 'RCPT-'.str_pad((string) $t->id, 5, '0', STR_PAD_LEFT);
    }

    protected function allowQuotation(Request $request, Quotation $q): void
    {
        $user = $request->user();
        abort_unless($user, 403);
        if ($user->isCustomerAccount()) {
            abort_unless(CustomerArea::quotations()->whereKey($q->id)->exists(), 404);

            return;
        }
        $this->staffOnly();
        abort_unless(QuotationResource::getEloquentQuery()->whereKey($q->id)->exists(), 404);
    }

    protected function allowBooking(Request $request, Booking $b): void
    {
        $user = $request->user();
        abort_unless($user, 403);
        if ($user->isCustomerAccount()) {
            abort_unless(CustomerArea::bookings()->whereKey($b->id)->exists(), 404);

            return;
        }
        $this->staffOnly();
        abort_unless(BookingResource::getEloquentQuery()->whereKey($b->id)->exists(), 404);
    }

    /** Drivers have no customer documents. */
    protected function staffOnly(): void
    {
        abort_if(Access::isDriverOnly() || ! auth()->user()?->hasCrmRole() || ! auth()->user()?->is_active, 403);
    }
}
