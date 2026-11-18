<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade brief 4 Nov, Phase 2 (section 14): sending mailboxes managed in the CRM,
 * email templates, the email log and customers' marketing consent. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('label', 60);
            $t->string('from_name', 120);
            $t->string('from_address', 190);
            $t->string('reply_to', 190)->nullable();
            $t->string('preset', 30)->nullable();
            $t->string('host', 190);
            $t->unsignedSmallInteger('port');
            $t->string('encryption', 10)->default('tls');   // tls / ssl / none
            $t->string('username', 190)->nullable();
            $t->text('password')->nullable();                 // encrypted, never shown again
            $t->boolean('is_default')->default(false);
            $t->string('status', 20)->default('untested');    // untested / connected / failed
            $t->dateTime('last_tested_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->unsignedInteger('per_hour')->nullable();      // announcements: most emails per hour
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('email_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('audience', 20)->default('customer');  // customer / driver / staff / any
            $t->string('subject_en', 190);
            $t->text('body_en');
            $t->string('subject_ar', 190)->nullable();
            $t->text('body_ar')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('email_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $t->string('mailer', 60)->nullable();
            $t->string('type', 40)->default('other');        // routing type
            $t->string('kind', 80)->nullable();               // what it was (welcome, quotation, announcement…)
            $t->string('to_email', 190);
            $t->string('to_name', 190)->nullable();
            $t->string('subject', 255)->nullable();
            $t->longText('body')->nullable();                 // kept for the retention period
            $t->string('status', 20)->default('queued');     // queued / sending / sent / failed
            $t->text('error')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->nullableMorphs('related');                    // booking, quotation, lead, customer…
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // recipient account
            $t->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('message_id', 190)->nullable();
            $t->string('batch', 40)->nullable();              // announcements
            $t->dateTime('send_after')->nullable();           // announcements: waiting for the hourly limit
            $t->json('payload')->nullable();                  // announcements: the text until it is sent
            $t->dateTime('sent_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['mail_account_id', 'sent_at']);
            $t->index(['batch', 'status']);
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->dateTime('marketing_consent_at')->nullable();
            $t->string('marketing_consent_source', 60)->nullable();
            $t->dateTime('unsubscribed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['marketing_consent_at', 'marketing_consent_source', 'unsubscribed_at']);
        });
        Schema::dropIfExists('email_log');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('mail_accounts');
    }
};
