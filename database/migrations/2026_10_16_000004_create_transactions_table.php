<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts: money in (revenue) and money out (expenses). Simple recording and
 * reporting, not a full accounting / VAT system (Schedule A9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();                 // income / expense
            $table->date('date')->index();
            $table->string('category')->index();
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->nullable(); // for partially paid income
            $table->string('status')->default('paid')->index();
            $table->string('method')->nullable();            // cash / bank_transfer / card / online / other
            $table->date('due_date')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('related');               // the car / yacht / trip it belongs to
            $table->string('reference')->nullable()->index();  // invoice / receipt / bank ref
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
