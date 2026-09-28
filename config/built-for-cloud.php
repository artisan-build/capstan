<?php

declare(strict_types=1);

use App\Auth\CapstanCredentialDeclaration;
use App\Http\Controllers\DashboardController;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;

return [
    'manifest' => [
        'name' => 'Capstan',
        'slug' => 'capstan',
        'description' => 'Fork-and-deploy AI ecosystem server for the Solo fleet.',
        'icon' => 'https://scalpels.app/img/products/transparent/capstan.png',
        'product_url' => 'https://scalpels.app/products/capstan',
    ],

    'dashboard' => DashboardController::class,

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => CapstanCredentialDeclaration::class,
        'session_guard' => null,
        'app_purposes' => [
            CapstanCredentialDeclaration::ARTIFACT_INGEST => CredentialPurpose::Consumption->value,
            CapstanCredentialDeclaration::POSTMASTER_POLL => CredentialPurpose::Mcp->value,
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
            CapstanCredentialDeclaration::ARTIFACT_INGEST,
            CapstanCredentialDeclaration::POSTMASTER_POLL,
        ],
    ],
];
