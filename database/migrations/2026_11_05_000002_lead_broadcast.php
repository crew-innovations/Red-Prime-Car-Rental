<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 5 Nov ("Make it simple", S3): leads go to every agent, the first to accept owns everything.
 *
 * - leads.broadcast_at / claim_channel / broadcast_reminded_at: when the lead was sent to all
 *   agents, how it was taken (crm / email / push / bell) and when the "still waiting" reminder went.
 * - lead_claim_tokens: one single-use link per agent and lead (email / push), stored hashed.
 * - bookings.cancel_requested_*: an agent asks to cancel a paid booking; an Admin decides.
 * - Settings: a saved lead_assignment "manual" becomes "broadcast" (the brief's flow) and a saved empty
 *   lead_accept_minutes becomes 60 (decision H-35). Old values are kept for down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            if (! Schema::hasColumn('leads', 'broadcast_at')) {
                $t->timestamp('broadcast_at')->nullable()->after('accepted_at');
                $t->string('claim_channel', 20)->nullable()->after('broadcast_at');
                $t->timestamp('broadcast_reminded_at')->nullable()->after('claim_channel');
                $t->index(['assigned_to', 'broadcast_at']);
            }
        });

        if (! Schema::hasTable('lead_claim_tokens')) {
            Schema::create('lead_claim_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->char('token_hash', 64)->unique();
                $t->timestamp('expires_at');
                $t->timestamp('used_at')->nullable();
                $t->timestamps();
                $t->index(['lead_id', 'user_id']);
            });
        }

        Schema::table('bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('bookings', 'cancel_requested_at')) {
                $t->timestamp('cancel_requested_at')->nullable();
                $t->foreignId('cancel_requested_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('cancel_request_reason', 500)->nullable();
            }
        });

        if (Schema::hasTable('settings')) {
            // Only rows that were saved before are changed; without a row the new defaults apply
            // (App\Models\Setting::DEFAULTS: broadcast, 60 minutes).
            $rows = DB::table('settings')->whereIn('key', ['lead_assignment', 'lead_accept_minutes'])->pluck('value', 'key')
                ->map(fn ($v) => json_decode((string) $v, true))->all();
            if ($rows) {
                DB::table('settings')->updateOrInsert(['key' => 's3_settings_before'], ['value' => json_encode($rows), 'updated_at' => now()]);
            }
            if (array_key_exists('lead_assignment', $rows) && in_array($rows['lead_assignment'], [null, 'manual'], true)) {
                DB::table('settings')->where('key', 'lead_assignment')->update(['value' => json_encode('broadcast'), 'updated_at' => now()]);
            }
            if (array_key_exists('lead_accept_minutes', $rows) && in_array($rows['lead_accept_minutes'], [null, ''], true)) {
                DB::table('settings')->where('key', 'lead_accept_minutes')->update(['value' => json_encode(60), 'updated_at' => now()]);
            }
            \Illuminate\Support\Facades\Cache::forget('crm.settings');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings') && ($row = DB::table('settings')->where('key', 's3_settings_before')->first())) {
            foreach ((array) json_decode($row->value, true) as $k => $v) {
                DB::table('settings')->where('key', $k)->update(['value' => json_encode($v)]);
            }
            DB::table('settings')->where('key', 's3_settings_before')->delete();
            \Illuminate\Support\Facades\Cache::forget('crm.settings');
        }

        Schema::table('bookings', function (Blueprint $t) {
            if (Schema::hasColumn('bookings', 'cancel_requested_at')) {
                $t->dropConstrainedForeignId('cancel_requested_by');
                $t->dropColumn(['cancel_requested_at', 'cancel_request_reason']);
            }
        });
        Schema::dropIfExists('lead_claim_tokens');
        Schema::table('leads', function (Blueprint $t) {
            if (Schema::hasColumn('leads', 'broadcast_at')) {
                $t->dropIndex(['assigned_to', 'broadcast_at']);
                $t->dropColumn(['broadcast_at', 'claim_channel', 'broadcast_reminded_at']);
            }
        });
    }
};
