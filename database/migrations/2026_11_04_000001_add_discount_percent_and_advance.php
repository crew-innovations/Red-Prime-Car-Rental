<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade brief 4 Nov, Phase 1 (sections 10, 11, 12): discount as a percentage,
 * the discount given against the price list (for the "Discounts given" report),
 * the advance percentage and the quotation PDF language. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotations', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->decimal('base_discount_percent', 5, 2)->nullable();  // % off the rental line
                $t->string('discount_mode', 10)->nullable();             // amount / percent (overall discount)
                $t->decimal('discount_percent', 5, 2)->nullable();       // overall discount when mode = percent
                $t->decimal('list_discount_amount', 12, 2)->nullable();  // price list − agreed (before VAT)
                $t->decimal('list_discount_percent', 5, 2)->nullable();
                $t->string('price_rejected_reason')->nullable();
                $t->decimal('advance_percent', 5, 2)->nullable();
                $t->string('advance_reason')->nullable();                // below the minimum advance
                if ($table === 'quotations') {
                    $t->string('pdf_language', 5)->nullable();           // en / ar / both
                } else {
                    $t->decimal('advance_required', 12, 2)->nullable();  // worked out by syncBalance()
                    $t->dateTime('advance_reached_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['quotations', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropColumn(array_merge(
                    ['base_discount_percent', 'discount_mode', 'discount_percent', 'list_discount_amount', 'list_discount_percent', 'price_rejected_reason', 'advance_percent', 'advance_reason'],
                    $table === 'quotations' ? ['pdf_language'] : ['advance_required', 'advance_reached_at'],
                ));
            });
        }
    }
};
