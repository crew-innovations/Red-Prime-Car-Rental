<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings (company, VAT, quotation and document rules), customer documents
 * with secure upload links (Schedule A11), quotations (A8), and VAT fields on
 * bookings. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        Schema::create('document_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->json('types');
            $t->timestamp('expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->unsignedInteger('upload_count')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('customer_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('document_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type')->index();
            $t->string('status')->default('received')->index(); // received / approved / rejected / replacement
            $t->string('path');                                 // on the private disk
            $t->string('original_name')->nullable();
            $t->string('mime')->nullable();
            $t->unsignedInteger('size')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('uploaded_via')->default('staff');        // staff / link
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('quotations', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->string('status')->default('draft')->index();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('service_type');
            $t->nullableMorphs('bookable');
            $t->foreignId('trip_date_id')->nullable()->constrained()->nullOnDelete();
            $t->string('drive_mode')->nullable();
            $t->dateTime('start_at')->nullable();
            $t->dateTime('end_at')->nullable();
            $t->unsignedSmallInteger('guests')->nullable();
            $t->string('pickup_location')->nullable();

            $t->string('rate_type')->nullable();
            $t->decimal('quantity', 8, 2)->nullable();
            $t->decimal('rate', 10, 2)->nullable();
            $t->decimal('base_amount', 12, 2)->default(0);
            $t->json('extras')->nullable();                     // [{label, amount}]
            $t->decimal('extras_amount', 12, 2)->default(0);
            $t->decimal('discount', 12, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('security_deposit', 12, 2)->default(0);
            $t->decimal('advance_required', 12, 2)->nullable();

            $t->date('valid_until')->nullable()->index();
            $t->text('customer_notes')->nullable();
            $t->text('terms')->nullable();
            $t->text('internal_notes')->nullable();
            $t->string('public_token', 64)->nullable()->unique();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->timestamp('expired_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::table('bookings', function (Blueprint $t) {
            $t->foreignId('quotation_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $t->decimal('tax_rate', 5, 2)->nullable()->after('discount');
            $t->boolean('tax_inclusive')->default(false)->after('tax_rate');
            $t->decimal('tax_amount', 12, 2)->default(0)->after('tax_inclusive');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('quotation_id');
            $t->dropColumn(['tax_rate', 'tax_inclusive', 'tax_amount']);
        });
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('settings');
    }
};
