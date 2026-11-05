<?php

declare(strict_types=1);

/**
 * Audit redaction: any attribute whose key matches one of these names (case-insensitive) is replaced by
 * "[REDACTED]" in audit before/after JSON and listed in `redactions`. Models may add their own via
 * `$auditRedact`. Secret-like values are additionally scrubbed by SecretRedactor.
 *
 * @see SRS NFR-03, M13-05, G-06
 */
return [
    'redact_keys' => [
        'password', 'password_confirmation', 'remember_token', 'token', 'token_hash', 'secret', 'api_key', 'apikey',
        'mfa_secret', 'mfa_recovery_codes', 'recovery_codes', 'id_number', 'nric', 'passport_no', 'account_no',
        'bank_account_no', 'clinical_note', 'diagnosis', 'vault_item_reference',
    ],
    'ignore_keys' => ['updated_at', 'created_at'],
    'placeholder' => '[REDACTED]',
];
