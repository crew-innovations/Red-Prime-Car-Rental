<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payment links (brief 28 Oct-2, Phase 13). One row per amount asked
 * for; the customer picks a connected gateway on our /pay page and the money
 * is recorded only after the provider confirms it server-to-server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->nullable()->unique();       // PR-00001
            $t->string('token', 64)->unique();                     // /pay/{token}
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('purpose', 20);                             // advance / balance / security_deposit / other
            $t->string('description')->nullable();
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3)->default('AED');
            $t->json('providers')->nullable();                     // allowed gateways (empty = every connected one)
            $t->string('status', 20)->default('open');             // open / processing / paid / review / failed / cancelled / expired / refunded / partly_refunded
            $t->string('provider', 20)->nullable();                // gateway used for the last attempt / the payment
            $t->string('mode', 10)->nullable();                    // test / live at the time of payment
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('provider_ref', 191)->nullable();           // checkout session / order id of the last attempt
            $t->string('provider_payment_id', 191)->nullable();    // payment / capture id once paid
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('refunded_amount', 12, 2)->default(0);
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->json('meta')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['booking_id', 'status']);
            $t->index(['quotation_id', 'status']);
            $t->index(['provider', 'provider_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
