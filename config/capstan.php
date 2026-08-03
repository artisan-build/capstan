<?php

return [
    // New hat pattern: add a feature class, a config key here, and a matching .env line.
    'features' => [
        'artifacts' => env('CAPSTAN_FEATURE_ARTIFACTS', false),
    ],
];
