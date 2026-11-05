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

    // The read-only AUD role may still write these resources (SRS M01-09: only FIN/OWN/AUD set tax treatment).
    'read_only_role_write_exceptions' => ['tax_review'],

    // Dev/test-only fake sentinel used by leak tests (UAT-08, M13-05). Never a real secret.
    'secret_sentinel' => env('SECRET_SENTINEL', 'SENTINEL-DO-NOT-LEAK-9f3a7c1e'),

    // Seeded Owner login (dev/test). Production must override SEED_OWNER_PASSWORD.
    'seed_owner' => [
        'name' => env('SEED_OWNER_NAME', 'Julaiha'),
        'email' => env('SEED_OWNER_EMAIL', 'owner@infernix.local'),
        'password' => env('SEED_OWNER_PASSWORD', ''),
    ],

    'security' => [
        'recent_auth_minutes' => 10,
        'invitation_days' => 7,
        'mfa_issuer' => 'Infernix One',
    ],

    'vault' => ['driver' => env('VAULT_DRIVER', 'null')],
    // Document Centre (M06). Files live in a PRIVATE disk; downloads only via signed, policy-checked routes.
    'documents' => [
        'disk' => env('FILESYSTEM_DISK', 'documents'),
        'scanner' => env('DOCUMENT_SCANNER', 'null'),
        'clamav_binary' => env('CLAMAV_BINARY', '/usr/bin/clamdscan'),
        'signed_url_ttl_minutes' => 5,
        'max_size_kb' => (int) env('DOCUMENT_MAX_SIZE_KB', 25 * 1024),
        // extension => MIME types accepted for that extension (content-sniffed, never trusted from the client)
        'allowed' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'csv' => ['text/csv', 'text/plain', 'application/csv'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ],
        'link_types' => [
            'attachment', 'receipt', 'invoice', 'contract', 'agreement', 'nda', 'photo', 'evidence', 'warranty',
            'certificate', 'report', 'logo', 'signature', 'ssm_registration', 'issued_document', 'other',
        ],
        // Linking with these types freezes the document (issued commercial documents never change, M03-07).
        'freezing_link_types' => ['issued_document'],
        // Which roles may see each classification (owner of the file and explicit shares always may).
        'classification_access' => [
            'public_internal' => '*',
            'confidential' => '*',
            'restricted' => ['OWN', 'FIN', 'HR', 'PM', 'AUD'],
            'medical' => ['OWN', 'HR'],
        ],
        'default_retention_rule' => 'general_7y',
    ],

    'pdf' => ['driver' => env('PDF_DRIVER', 'browsershot')],
    'features' => [
        'myinvois' => (bool) env('FEATURE_MYINVOIS', false),
        'depreciation_posting' => (bool) env('FEATURE_DEPRECIATION_POSTING', false),
    ],
];
