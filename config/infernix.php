<?php

declare(strict_types=1);

/**
 * Infernix One platform settings. Business policy values live in DB dictionaries (G-09);
 * this file only holds deployment-level defaults.
 */
return [
    'company' => [
        'name' => 'Infernix Evolv Hub',
        'tagline' => 'Intelligence for Better Business',
    ],
    'currency' => 'MYR',
    'timezone' => 'Asia/Kuala_Lumpur',
    'locales' => ['en', 'ms'],

    'roles' => ['OWN', 'FIN', 'PM', 'HR', 'STF', 'AUD', 'APP'],

    // Dev/test-only fake sentinel used by leak tests (UAT-08, M13-05). Never a real secret.
    'secret_sentinel' => env('SECRET_SENTINEL', 'SENTINEL-DO-NOT-LEAK-9f3a7c1e'),

    // Seeded Owner login (dev/test). Production must override SEED_OWNER_PASSWORD.
    'seed_owner' => [
        'name' => env('SEED_OWNER_NAME', 'Julaiha'),
        'email' => env('SEED_OWNER_EMAIL', 'owner@infernix.local'),
        'password' => env('SEED_OWNER_PASSWORD', ''),
    ],

    'vault' => ['driver' => env('VAULT_DRIVER', 'null')],
    'documents' => [
        'disk' => env('FILESYSTEM_DISK', 'documents'),
        'scanner' => env('DOCUMENT_SCANNER', 'null'),
        'signed_url_ttl_minutes' => 5,
        'max_size_kb' => 25 * 1024,
        'allowed_mimes' => [
            'application/pdf', 'image/jpeg', 'image/png', 'text/csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],
    'pdf' => ['driver' => env('PDF_DRIVER', 'browsershot')],
    'features' => [
        'myinvois' => (bool) env('FEATURE_MYINVOIS', false),
        'depreciation_posting' => (bool) env('FEATURE_DEPRECIATION_POSTING', false),
    ],
];
