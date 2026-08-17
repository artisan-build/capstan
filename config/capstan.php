<?php

$csv = function (string $key): array {
    $value = env($key, '');

    return array_values(array_filter(explode(',', is_string($value) ? $value : '')));
};

return [
    // New hat pattern: add a feature class, a config key here, and a matching .env line.
    'features' => [
        'artifacts' => env('CAPSTAN_FEATURE_ARTIFACTS', false),
        'postmaster' => env('CAPSTAN_FEATURE_POSTMASTER', false),
    ],

    'postmaster' => [
        'server_id' => env('CAPSTAN_SERVER_ID'),
        'signing_key' => env('CAPSTAN_POSTMASTER_SIGNING_KEY'),
        'poll' => [
            // Caps work and response size per poll. Unacked messages beyond this
            // batch remain eligible for a later poll in deterministic order.
            'max_inbound' => env('CAPSTAN_POSTMASTER_MAX_INBOUND', 50),
        ],
        'probe' => [
            // Probes ride normal polls. Interval limits healthy cadence, timeout
            // drives the sweep, and backoff avoids hammering a failed spoke.
            'interval_seconds' => env('CAPSTAN_POSTMASTER_PROBE_INTERVAL_SECONDS', 300),
            'timeout_seconds' => env('CAPSTAN_POSTMASTER_PROBE_TIMEOUT_SECONDS', 900),
            'backoff_seconds' => env('CAPSTAN_POSTMASTER_PROBE_BACKOFF_SECONDS', 1800),
        ],
    ],

    'artifacts' => [
        'max_content_bytes' => env('CAPSTAN_ARTIFACT_MAX_CONTENT_BYTES', 1024 * 1024),
        'render_origin' => env('CAPSTAN_ARTIFACT_RENDER_ORIGIN'),
        'csp_allowlist' => [
            'script_src' => $csv('CAPSTAN_ARTIFACT_CSP_SCRIPT_SRC'),
            'style_src' => $csv('CAPSTAN_ARTIFACT_CSP_STYLE_SRC'),
            'font_src' => $csv('CAPSTAN_ARTIFACT_CSP_FONT_SRC'),
            'img_src' => $csv('CAPSTAN_ARTIFACT_CSP_IMG_SRC'),
        ],
        'allowed_content_types' => [
            'text/html',
            'application/xhtml+xml',
        ],
    ],
];
