<?php

/*
 * Browser access to the public API (/api/*). Allowed websites come from
 * CORS_ALLOWED_ORIGINS (comma separated) plus Business settings › Website
 * connection (added at runtime in AppServiceProvider). Nothing else is allowed.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
