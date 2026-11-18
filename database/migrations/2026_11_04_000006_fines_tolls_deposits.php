<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 of the 4 Nov brief: booking charge lines and pass-through money (C.1, C.2), statement
 * imports (C.3), traffic fines (2), tolls (3) and deposit settlement (4). Additive only; down()
 * removes exactly what up() adds. No card number, expiry or CVC is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $t->string('type', 30);                         // fine, admin_fee, salik, darb, parking, toll_fee, damage, other
            $t->string('label', 255);
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_amount', 12, 2)->default(0);
            $t->decimal('amount', 12, 2);
            $t->string('nature', 20)->default('revenue');   // revenue / pass_through
            $t->nullableMorphs('source');                    // traffic_fine, toll_trip, …
            $t->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->dateTime('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['booking_id', 'voided_at']);
        });

        Schema::table('bookings', function (Blueprint $t) {
            $t->decimal('charge_lines_total', 12, 2)->default(0);   // active charge lines (fines, tolls, fees)
            $t->decimal('pass_through_total', 12, 2)->default(0);   // of which pass-through (not revenue)
            $t->date('deposit_refundable_on')->nullable();
        });

        Schema::table('transactions', function (Blueprint $t) {
            $t->boolean('pass_through')->default(false);            // fines / tolls paid to the authority for a customer
            $t->nullableMorphs('source');
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->string('kind', 20)->default('booking');              // booking / charges (fines and tolls after the invoice)
        });

        Schema::table('cars', function (Blueprint $t) {
            $t->string('toll_tag_no', 40)->nullable()->index();      // Salik tag / Darb account vehicle
        });

        Schema::create('import_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);                                  // fines / tolls
            $t->string('source', 60);                                // authority or toll type
            $t->json('columns');                                     // field => column heading
            $t->timestamps();
            $t->unique(['kind', 'source']);
        });

        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->string('source', 60);
            $t->string('file_path')->nullable();                     // private disk
            $t->string('file_name')->nullable();
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_new')->default(0);
            $t->unsignedInteger('rows_duplicate')->default(0);
            $t->unsignedInteger('rows_error')->default(0);
            $t->unsignedInteger('rows_matched')->default(0);
            $t->json('errors')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('traffic_fines', function (Blueprint $t) {
            $t->id();
            $t->string('authority', 60);                             // Dubai Police, RTA, Abu Dhabi Police, …
            $t->string('ticket_no', 80);
            $t->dateTime('offence_at');
            $t->string('plate', 40)->nullable();
            $t->foreignId('car_id')->nullable()->constrained('cars')->nullOnDelete();
            $t->string('offence', 255)->nullable();
            $t->string('location', 255)->nullable();
            $t->decimal('amount', 12, 2);
            $t->unsignedSmallInteger('black_points')->nullable();
            $t->string('status', 20)->default('new');
            $t->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete(); // who drove (information only)
            $t->string('match_note', 255)->nullable();
            $t->decimal('admin_fee', 12, 2)->nullable();
            $t->dateTime('charged_at')->nullable();
            $t->dateTime('paid_at')->nullable();                      // paid to the authority
            $t->string('paid_reference', 80)->nullable();
            $t->string('dispute_note', 500)->nullable();
            $t->string('path')->nullable();                           // the fine notice (private disk)
            $t->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['authority', 'ticket_no']);
            $t->index(['status', 'offence_at']);
        });

        Schema::create('toll_trips', function (Blueprint $t) {
            $t->id();
            $t->string('toll_type', 20);                             // salik / darb / parking
            $t->string('tag_no', 40)->nullable();
            $t->string('plate', 40)->nullable();
            $t->foreignId('car_id')->nullable()->constrained('cars')->nullOnDelete();
            $t->dateTime('passed_at');
            $t->string('gate', 120)->nullable();
            $t->decimal('amount', 10, 2);
            $t->string('status', 20)->default('new');                // new / matched / charged / company / needs_review
            $t->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $t->foreignId('booking_charge_id')->nullable()->constrained('booking_charges')->nullOnDelete();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete(); // company cost
            $t->string('match_note', 255)->nullable();
            $t->string('dedupe_key', 191)->unique();                 // type + tag/plate + time + gate
            $t->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'passed_at']);
        });

        Schema::create('toll_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('toll_type', 20);
            $t->string('name', 120);
            $t->string('account_no', 60)->nullable();
            $t->decimal('balance', 12, 2)->default(0);
            $t->decimal('low_balance', 12, 2)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('toll_account_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('toll_account_id')->constrained('toll_accounts')->cascadeOnDelete();
            $t->string('kind', 20);                                  // topup / statement / adjustment
            $t->decimal('amount', 12, 2);                            // + top-up, − usage
            $t->decimal('balance_after', 12, 2);
            $t->date('date');
            $t->string('note', 255)->nullable();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('deposit_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $t->decimal('held', 12, 2);
            $t->decimal('owed', 12, 2);                               // balance due when settled
            $t->decimal('applied', 12, 2)->default(0);                // deposit used for the balance
            $t->decimal('refunded', 12, 2)->default(0);
            $t->decimal('to_collect', 12, 2)->default(0);             // still owed after the deposit
            $t->json('lines');                                        // snapshot shown on the statement
            $t->string('refund_method', 30)->nullable();
            $t->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->foreignId('payment_request_id')->nullable()->constrained('payment_requests')->nullOnDelete();
            $t->string('preauth_release_ref', 80)->nullable();
            $t->string('note', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('deposit_preauths', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $t->string('provider', 40);                               // POS, Stripe, …
            $t->string('reference', 80);                              // authorisation / POS reference
            $t->string('card_last4', 4)->nullable();                  // never the full number, expiry or CVC
            $t->decimal('amount', 12, 2);
            $t->date('held_on');
            $t->date('expires_on')->nullable();
            $t->string('status', 20)->default('held');                // held / captured / released / expired
            $t->decimal('captured_amount', 12, 2)->nullable();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_preauths');
        Schema::dropIfExists('deposit_settlements');
        Schema::dropIfExists('toll_account_movements');
        Schema::dropIfExists('toll_accounts');
        Schema::dropIfExists('toll_trips');
        Schema::dropIfExists('traffic_fines');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_mappings');
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn('toll_tag_no'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('transactions', function (Blueprint $t) {
            $t->dropMorphs('source');
            $t->dropColumn('pass_through');
        });
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['charge_lines_total', 'pass_through_total', 'deposit_refundable_on']));
        Schema::dropIfExists('booking_charges');
    }
};
