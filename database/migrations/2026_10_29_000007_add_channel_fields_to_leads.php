<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead inbox (brief 28 Oct-2, Phase 15): where a lead came in (website, Meta,
 * TikTok, Snapchat, a shared form, typed by staff), the ad / campaign / form it
 * came from, and the answers given. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('channel', 20)->nullable()->index()->after('lead_source_id'); // website / meta / tiktok / snapchat / form / manual
            $t->json('platform')->nullable()->after('external_id');                   // page, form, campaign, ad set, ad (ids and names)
            $t->json('answers')->nullable()->after('platform');                       // form answers as given
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex(['channel']);
            $t->dropColumn(['channel', 'platform', 'answers']);
        });
    }
};
