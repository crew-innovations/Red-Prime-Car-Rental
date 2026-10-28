<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One registry for every outside service (brief 28 Oct-2, Phases 3, 4.3, 12):
 * Google Maps, payment gateways, social platforms. Secrets are stored encrypted
 * (Laravel's encrypted cast, APP_KEY) and never sent to the browser.
 * "connected" only after a successful test or sign-in, never because fields are filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 40)->unique();              // google_maps, stripe, paymob, tabby, tamara, paypal, meta, tiktok, snapchat
            $t->boolean('enabled')->default(false);
            $t->string('mode', 10)->default('test');           // test / live
            $t->json('config')->nullable();                     // non-secret settings (ids, currencies, options)
            $t->text('secrets')->nullable();                    // encrypted JSON
            $t->string('status', 20)->default('not_set_up');    // not_set_up / configured / connected / error / expired
            $t->string('account_label')->nullable();            // connected page / account / merchant shown to staff
            $t->json('permissions')->nullable();
            $t->dateTime('token_expires_at')->nullable();
            $t->dateTime('last_tested_at')->nullable();
            $t->dateTime('last_success_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 40)->index();
            $t->string('direction', 10);                        // in (webhook) / out (API call)
            $t->string('event', 80)->nullable();
            $t->string('external_id', 191)->nullable();         // provider event id, for idempotency
            $t->string('status', 20);                           // ok / failed / duplicate / rejected / pending
            $t->unsignedSmallInteger('attempts')->default(1);
            $t->string('error', 500)->nullable();
            $t->json('summary')->nullable();                    // what was done (no secrets, minimal personal data)
            $t->text('payload')->nullable();                    // encrypted raw payload, kept for troubleshooting
            $t->dateTime('next_retry_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integrations');
    }
};
