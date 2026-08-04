<?php

$csv = function (string $key): array {
    $value = env($key, '');

    return array_values(array_filter(explode(',', is_string($value) ? $value : '')));
};

return [
    // New hat pattern: add a feature class, a config key here, and a matching .env line.
    'features' => [
        'artifacts' => env('CAPSTAN_FEATURE_ARTIFACTS', false),
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
