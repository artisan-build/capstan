<?php

return [
    // New hat pattern: add a feature class, a config key here, and a matching .env line.
    'features' => [
        'artifacts' => env('CAPSTAN_FEATURE_ARTIFACTS', false),
    ],

    'artifacts' => [
        'max_content_bytes' => env('CAPSTAN_ARTIFACT_MAX_CONTENT_BYTES', 1024 * 1024),
        'allowed_content_types' => [
            'text/html',
            'application/xhtml+xml',
        ],
    ],
];
