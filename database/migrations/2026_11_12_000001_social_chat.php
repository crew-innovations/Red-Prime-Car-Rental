<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12 Nov (Bilal: messages from Instagram / Facebook / WhatsApp become leads and the agent
 * carries on the chat in the CRM). Additive: the chat tables get the outside channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_threads', function (Blueprint $t) {
            $t->string('channel', 20)->nullable()->after('kind');        // instagram / messenger / whatsapp (null = inside the CRM)
            $t->string('external_id', 100)->nullable()->after('channel'); // the person's id on that channel (WhatsApp: their number)
            $t->string('external_name')->nullable()->after('external_id');
            $t->foreignId('lead_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $t->dateTime('last_inbound_at')->nullable()->after('last_message_at'); // Meta's reply window counts from here
            $t->unique(['channel', 'external_id']);
        });
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->string('external_id', 191)->nullable()->unique()->after('chat_thread_id'); // Meta's message id: each message once
            $t->string('attachment_url', 1000)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->dropUnique(['external_id']);
            $t->dropColumn(['external_id', 'attachment_url']);
        });
        Schema::table('chat_threads', function (Blueprint $t) {
            $t->dropUnique(['channel', 'external_id']);
            $t->dropConstrainedForeignId('lead_id');
            $t->dropColumn(['channel', 'external_id', 'external_name', 'last_inbound_at']);
        });
    }
};
