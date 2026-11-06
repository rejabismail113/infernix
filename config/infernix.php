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

    // The read-only AUD role may still write these resources (SRS M01-09: only FIN/OWN/AUD set tax treatment;
    // M11-05: an auditor's export is an audited Export Centre record, A-37).
    'read_only_role_write_exceptions' => ['tax_review', 'exports'],

    // M04 / UAT-07: roles that may book time to projects they are not assigned to (the entry is flagged as an
    // exception and audited). PLACEHOLDER — confirm with the owner.
    'timesheets' => ['unassigned_exception_roles' => ['OWN']],

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
            'certificate', 'report', 'logo', 'signature', 'ssm_registration', 'issued_document', 'client_po', 'export_file', 'memo', 'appointment', 'handover', 'itinerary', 'other',
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

    // M05 capitalisation policy (M05-02) — PLACEHOLDER thresholds pending accountant sign-off (A-64).
    'assets' => [
        'capital_threshold' => env('ASSET_CAPITAL_THRESHOLD', '2000.00'),
        'low_value_threshold' => env('ASSET_LOW_VALUE_THRESHOLD', '500.00'),
        'warranty_alert_days' => 30,
    ],

    // M13 vault (M13-03). Drivers: null (not configured: reference + deep link only), fake (dev/test ONLY, returns
    // the fake sentinel), bitwarden (Bitwarden Secrets Manager), onepassword (1Password Connect). Credentials for
    // the real drivers come from the environment only — never the database (M13-02).
    'vault' => [
        'driver' => env('VAULT_DRIVER', 'null'),
        'reveal_seconds' => 30,
        'bitwarden' => ['api_url' => env('BWS_API_URL', 'https://api.bitwarden.com'), 'identity_url' => env('BWS_IDENTITY_URL', 'https://identity.bitwarden.com'),
            'access_token' => env('BWS_ACCESS_TOKEN'), 'web_vault_url' => env('BWS_WEB_URL', 'https://vault.bitwarden.com')],
        'onepassword' => ['connect_url' => env('OP_CONNECT_HOST'), 'token' => env('OP_CONNECT_TOKEN'), 'vault_id' => env('OP_VAULT_ID')],
    ],

    // Optional MyInvois / e-Invoice (features.myinvois). Eligibility and fields need accountant verification (A-82).
    'einvoice' => [
        'rate_per_minute' => (int) env('EINVOICE_RATE_PER_MINUTE', 30),
        'max_attempts' => (int) env('EINVOICE_MAX_ATTEMPTS', 6),
        'backoff_seconds' => (int) env('EINVOICE_BACKOFF_SECONDS', 60),
        'stub_fail_times' => 0,
        'supplier_tin' => env('EINVOICE_SUPPLIER_TIN'), // PLACEHOLDER — the company's LHDN TIN
    ],

    'pdf' => ['driver' => env('PDF_DRIVER', 'browsershot')],
    'features' => [
        'myinvois' => (bool) env('FEATURE_MYINVOIS', false),
        'depreciation_posting' => (bool) env('FEATURE_DEPRECIATION_POSTING', false),
        'vault' => (bool) env('FEATURE_VAULT', false),
    ],
];
