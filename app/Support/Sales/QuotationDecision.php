<?php

namespace App\Support\Sales;

use App\Enums\QuotationStatus;
use App\Models\DocumentRequest;
use App\Models\Quotation;
use App\Models\Setting;
use App\Support\Drivers\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer accepts or declines a quotation (28 Oct brief, 3.4). One path for
 * the customer account, the secure quotation link and staff recording it.
 *
 * Accepting: checks the quote is still open and valid, records who / how /
 * when and the exact offer, creates or links the customer profile, asks for
 * missing documents and tells the agent. It does NOT book or hold the car:
 * that only happens when the booking is created and availability is checked.
 */
class QuotationDecision
{
    public const VIA = ['portal' => 'Customer account', 'link' => 'Quotation link', 'staff' => 'Recorded by staff'];

    /**
     * @return array{quotation: Quotation, documents_url: ?string, missing: array<int, string>}
     */
    public static function accept(Quotation $quotation, string $via, ?string $name = null, ?string $ip = null, ?string $userAgent = null): array
    {
        $documentsUrl = null;
        $missing = [];

        $quotation = DB::transaction(function () use ($quotation, $via, $name, $ip, $userAgent, &$documentsUrl, &$missing) {
            /** @var Quotation $q */
            $q = Quotation::query()->whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            if ($problem = $q->acceptProblem()) {
                throw ValidationException::withMessages(['quotation' => $problem]);
            }

            // Customer profile: created from the lead, or the existing one linked (never a duplicate).
            $customerId = $q->customer_id ?? $q->lead?->convertToCustomer()->id;
            if (! $customerId) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has no customer. Please contact us.']);
            }

            $q->forceFill([
                'status' => QuotationStatus::Accepted,
                'accepted_at' => now(),
                'accepted_via' => $via,
                'accepted_name' => $name ? mb_substr(trim($name), 0, 255) : null,
                'accepted_ip' => $ip,
                'accepted_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'accepted_terms' => self::snapshot($q),
                'customer_id' => $customerId,
            ])->save();
            $q->refresh();

            // Documents still needed for this service.
            $missing = collect($q->documentChecklist())->reject(fn ($row) => $row[2])->keys()->all();
            if ($missing && (bool) Setting::read('customer_can_upload_documents', true)) {
                $request = DocumentRequest::issue($q->customer, $missing, (int) Setting::read('upload_link_hours', 72));
                $documentsUrl = $request->url();
                $q->forceFill(['document_request_id' => $request->id])->saveQuietly();
            }

            return $q;
        });

        $who = self::VIA[$via] ?? $via;
        $body = 'AED '.number_format((float) $quotation->total, 2).' · '.$who.($missing ? ' · documents requested' : ' · documents complete').'. Next: create the booking.';
        Notify::person($quotation->lead?->assignee ?? $quotation->creator, 'Quotation '.$quotation->number.' accepted by '.($quotation->customerName() ?? 'the customer'), $body,
            \App\Filament\Resources\QuotationResource::getUrl('edit', ['record' => $quotation]), 'success');

        return ['quotation' => $quotation, 'documents_url' => $documentsUrl, 'missing' => $missing];
    }

    public static function decline(Quotation $quotation, string $via, string $reason): Quotation
    {
        $q = DB::transaction(function () use ($quotation, $via, $reason) {
            $q = Quotation::query()->whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($q->status, [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation], true)) {
                throw ValidationException::withMessages(['quotation' => 'This quotation can no longer be declined.']);
            }
            $q->forceFill(['status' => QuotationStatus::Rejected, 'rejected_at' => now(), 'rejected_via' => $via, 'rejection_reason' => mb_substr(trim($reason), 0, 255)])->save();

            return $q;
        });

        if ($via !== 'staff') {
            Notify::person($q->lead?->assignee ?? $q->creator, 'Quotation '.$q->number.' declined', $reason,
                \App\Filament\Resources\QuotationResource::getUrl('edit', ['record' => $q]), 'danger');
        }

        return $q;
    }

    /** The exact offer as the customer saw it when accepting. */
    public static function snapshot(Quotation $q): array
    {
        return [
            'number' => $q->number,
            'item' => $q->bookable?->name,
            'service' => $q->service_type?->getLabel(),
            'drive_mode' => $q->drive_mode?->getLabel(),
            'start_at' => $q->start_at?->toDateTimeString(),
            'end_at' => $q->end_at?->toDateTimeString(),
            'guests' => $q->guests,
            'delivery_method' => $q->delivery_method,
            'return_method' => $q->return_method,
            'base_amount' => (float) $q->base_amount,
            'base_label' => $q->price_breakdown['base_label'] ?? null,
            'lines' => collect((array) $q->extras)->map(fn ($l) => ['label' => $l['label'] ?? '', 'amount' => (float) ($l['amount'] ?? 0), 'waived' => (bool) ($l['waived'] ?? false)])->values()->all(),
            'discount' => (float) $q->discount,
            'tax_rate' => $q->tax_rate !== null ? (float) $q->tax_rate : null,
            'tax_amount' => (float) $q->tax_amount,
            'total' => (float) $q->total,
            'security_deposit' => (float) $q->security_deposit,
            'advance_required' => $q->advance_required !== null ? (float) $q->advance_required : null,
            'valid_until' => $q->valid_until?->toDateString(),
            'terms' => $q->terms,
            'customer_notes' => $q->customer_notes,
        ];
    }
}
