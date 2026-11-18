<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade brief 4 Nov, Phase 2 (section 13): automatic customer portal accounts.
 * Customers can be a Prospect (from a lead) before they become a Customer; login links
 * can be cancelled by sending a newer one. Additive only; existing customers stay "customer".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('status', 12)->default('customer')->index(); // prospect / customer
            $t->string('portal_note', 190)->nullable();             // e.g. "email belongs to a staff login"
        });
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedInteger('invite_version')->default(0);       // a newer link cancels older ones
            $t->dateTime('password_set_at')->nullable();
            $t->string('account_trigger', 20)->nullable();            // lead / quotation / booking / staff
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['invite_version', 'password_set_at', 'account_trigger']);
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['status', 'portal_note']);
        });
    }
};
