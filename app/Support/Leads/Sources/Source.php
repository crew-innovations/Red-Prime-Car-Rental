<?php

namespace App\Support\Leads\Sources;

use App\Models\Integration;
use Illuminate\Http\Request;

/** A platform that sends leads (Meta, TikTok, Snapchat). */
abstract class Source
{
    public function __construct(protected Integration $integration)
    {
    }

    abstract public static function key(): string;

    /** Platform's "is this your endpoint?" GET check. Null = not used by this platform. */
    public function challenge(Request $request): ?string
    {
        return null;
    }

    /**
     * Checks the signature and reads the leads. Throws \App\Support\Payments\InvalidWebhook
     * when it can't be trusted. Each item is an input for LeadIntake::receive() plus 'event_id'.
     */
    abstract public function leads(Request $request): array;

    abstract public static function test(Integration $i): array;

    protected function defaults(): array
    {
        return array_filter(['service_type' => $this->integration->config('default_service') ?: null]);
    }

    /** HMAC-SHA256 of the raw body, compared in constant time ("sha256=" prefix allowed). */
    protected static function hmacOk(string $raw, ?string $given, ?string $secret): bool
    {
        if (! $secret || ! $given) {
            return false;
        }
        $given = preg_replace('/^sha256=/i', '', trim($given));

        return hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($given));
    }
}
