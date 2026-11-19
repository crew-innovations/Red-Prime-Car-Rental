<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 19 Nov 2026 (suggestions 1–10, Bilal: "do 1-10"). Additive only: new nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Suggestion 2: which bank / cash account the money went into or came from; cheques.
        Schema::table('transactions', function (Blueprint $t) {
            $t->string('bank_account', 40)->nullable()->index();
            $t->string('cheque_number', 40)->nullable();
            $t->date('cheque_date')->nullable();
        });

        Schema::table('bookings', function (Blueprint $t) {
            $t->string('tax_emirate', 20)->nullable();            // suggestion 4: VAT report per emirate
            $t->string('marketplace', 60)->nullable();            // suggestion 8: booked through a marketplace
            $t->string('marketplace_ref', 80)->nullable();
            $t->decimal('marketplace_commission', 10, 2)->nullable();
        });

        Schema::table('rental_contracts', function (Blueprint $t) {
            $t->string('paper_number', 60)->nullable();           // suggestion 6: old paper contract number
            $t->string('sign_otp_hash')->nullable();              // suggestion 10: code sent before signing by link
            $t->dateTime('sign_otp_expires_at')->nullable();
            $t->unsignedTinyInteger('sign_otp_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', fn (Blueprint $t) => $t->dropColumn(['paper_number', 'sign_otp_hash', 'sign_otp_expires_at', 'sign_otp_attempts']));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['tax_emirate', 'marketplace', 'marketplace_ref', 'marketplace_commission']));
        Schema::table('transactions', function (Blueprint $t) {
            $t->dropIndex(['bank_account']);
            $t->dropColumn(['bank_account', 'cheque_number', 'cheque_date']);
        });
    }
};
