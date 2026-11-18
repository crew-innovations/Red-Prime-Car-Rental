<?php

/*
 * The public website (redprimerentals.com), served by this same app (M3, Bilal 29 Oct).
 *
 * WEBSITE_DOMAIN: the website's own domain, e.g. redprimerentals.com. The "www." address
 *   redirects to it. When empty it is taken from APP_URL ("crm.example.com" → "example.com");
 *   with a local APP_URL the website is served under /site on whatever address the app runs on.
 * WEBSITE_URL: the public address used in links, canonical tags and the sitemap.
 */
// When WEBSITE_DOMAIN is not set and the CRM runs on "crm.<domain>" (live: crm.redprimerentals.com),
// the website domain is that main domain. Local addresses (127.0.0.1, localhost) give none, so /site is used.
$crmHost = (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
$domain = env('WEBSITE_DOMAIN') ?: (str_starts_with($crmHost, 'crm.') && substr_count($crmHost, '.') >= 2 ? substr($crmHost, 4) : null);

return [
    'domain' => $domain ?: null,

    'url' => rtrim((string) env('WEBSITE_URL', $domain ? 'https://'.$domain : ''), '/'),

    // Prefix used only when no domain is set.
    'path' => 'site',

    'locales' => ['en', 'ar'],

    'default_locale' => 'en',

    // Where the "My account" button goes (the customer area of the CRM).
    'account_url' => env('WEBSITE_ACCOUNT_URL', rtrim((string) env('APP_URL', ''), '/').'/account'),
];
