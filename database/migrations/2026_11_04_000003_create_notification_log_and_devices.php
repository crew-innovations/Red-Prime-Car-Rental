<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade brief 4 Nov, Phase 2 (sections 9 and 16): one notification per event and person,
 * notification preferences and quiet hours, and the devices people installed the app on
 * (with their web push subscription). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $t) {
            $t->id();
            $t->string('event', 40);
            $t->string('dedupe_key', 191);                 // event + record (+ extra)
            $t->string('recipient', 60);                   // user:12 / mail:hash
            $t->timestamp('created_at')->nullable();
            $t->unique(['dedupe_key', 'recipient']);
        });

        Schema::create('user_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('app', 20);                          // driver / staff / customer
            $t->string('device_id', 64);                    // made by the browser, kept in its storage
            $t->string('label', 120)->nullable();           // "Android · Chrome"
            $t->string('user_agent', 255)->nullable();
            $t->boolean('installed')->default(false);       // opened as an installed app at least once
            $t->dateTime('installed_at')->nullable();
            $t->string('permission', 12)->default('default'); // granted / denied / default
            $t->text('push_endpoint')->nullable();          // encrypted
            $t->string('endpoint_hash', 64)->nullable()->unique();
            $t->text('push_keys')->nullable();              // encrypted JSON {p256dh, auth}
            $t->string('app_version', 20)->nullable();
            $t->string('session_id', 191)->nullable();
            $t->unsignedSmallInteger('push_failures')->default(0);
            $t->dateTime('last_push_at')->nullable();
            $t->dateTime('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'device_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->json('notification_prefs')->nullable();     // {event: {channel: bool}}
            $t->string('quiet_from', 5)->nullable();        // "22:00"
            $t->string('quiet_to', 5)->nullable();          // "07:00"
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['notification_prefs', 'quiet_from', 'quiet_to']);
        });
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('notification_log');
    }
};
