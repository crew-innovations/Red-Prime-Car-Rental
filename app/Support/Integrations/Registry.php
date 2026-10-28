<?php

namespace App\Support\Integrations;

/**
 * Every outside service the CRM can talk to, with what it needs (brief 28 Oct-2,
 * 3.4 / 12.1 / 4.3). Adding a provider = one entry here plus an adapter class;
 * the lead, booking and payment code does not change.
 *
 * Keys of an entry:
 *  label, group, description, docs (official setup page),
 *  config: [key => [label, help, default, options?]]  (not secret),
 *  secrets: [key => [label, help]]                    (encrypted, never shown again),
 *  required: secret keys needed before it can be used,
 *  requirements: what the business must arrange with the provider,
 *  tester: class with test(Integration): Result
 */
class Registry
{
    public static function all(): array
    {
        return [
            'google_maps' => [
                'label' => 'Google Maps',
                'group' => 'Maps',
                'description' => 'Road distance and travel time for limousine and transfer prices (Routes API), address search (Places API) and the route map (Maps Embed API).',
                'docs' => 'https://developers.google.com/maps/documentation/routes',
                'config' => [
                    'region' => ['label' => 'Country code for address search', 'help' => 'Two letters, e.g. ae.', 'default' => 'ae'],
                ],
                'secrets' => [
                    'server_key' => ['label' => 'Server key', 'help' => 'Used only by the CRM server. Restrict it to the Routes API and Places API (New), and to your server IP.'],
                    'browser_key' => ['label' => 'Browser key (map preview)', 'help' => 'Shown to the browser for the route map. Restrict it to the Maps Embed API and to https://crm.redprimerentals.com/*.'],
                ],
                'required' => ['server_key'],
                'requirements' => [
                    'A Google Cloud project with billing switched on.',
                    'Routes API, Places API (New) and Maps Embed API enabled.',
                    'Two API keys with the restrictions above.',
                ],
                'tester' => \App\Support\Maps\GoogleMaps::class,
            ],
            ...\App\Support\Payments\Payments::registryEntries(),
        ];
    }

    public static function get(string $provider): ?array
    {
        return static::all()[$provider] ?? null;
    }

    public static function groups(): array
    {
        return collect(static::all())->groupBy('group', preserveKeys: true)->map->keys()->map->all()->all();
    }
}
