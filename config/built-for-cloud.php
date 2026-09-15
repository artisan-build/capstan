<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Capstan',
        'slug' => 'capstan',
        'description' => 'Fork-and-deploy AI ecosystem server for the Solo fleet.',
        'icon' => null,
        'product_url' => 'https://github.com/artisan-build/capstan',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'capstan.artifact.ingest' => 'consumption',
            'capstan.postmaster.poll' => 'mcp',
        ],
    ],

    'ui' => [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => true,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => [
            'capstan.artifact.ingest',
            'capstan.postmaster.poll',
        ],
    ],
];
