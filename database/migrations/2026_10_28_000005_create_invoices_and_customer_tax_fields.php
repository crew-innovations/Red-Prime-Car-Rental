<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer documents (28 Oct brief, Priority 7). Additive only:
 * - invoices: invoices and credit notes issued for a booking, numbered in
 *   order, with a frozen copy of the lines (a quotation is not an invoice and
 *   a booking confirmation is not proof of payment, so each is its own document)
 * - customers.address / customers.trn: needed on a tax invoice to a VAT
 *   registered business customer
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20)->default('invoice');            // invoice / credit_note
            $t->string('number', 30)->unique();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // credit note → invoice
            $t->date('issued_on');
            $t->date('supply_date')->nullable();
            $t->boolean('is_tax_invoice')->default(false);          // VAT was switched on when issued
            $t->json('seller');                                     // company details as printed
            $t->json('buyer');                                      // customer details as printed
            $t->json('lines');                                      // [{label, qty, unit_price, amount}]
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount', 12, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->string('currency', 3)->default('AED');
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('issued');            // issued / void
            $t->string('void_reason')->nullable();
            $t->dateTime('voided_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['type', 'issued_on']);
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->text('address')->nullable()->after('company_name');
            $t->string('trn', 30)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['address', 'trn']);
        });
        Schema::dropIfExists('invoices');
    }
};
