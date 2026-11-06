<?php

declare(strict_types=1);

use App\Kernel\Audit\Redactor;
use App\Kernel\Secrets\SecretRedactor;

/** @see SRS NFR-03, M13-05 */
it('redacts configured keys case-insensitively and recursively', function (): void {
    [$clean, $keys] = (new Redactor(['extra_secret']))->redact(['Password' => 'x', 'nested' => ['NRIC' => '1', 'ok' => 2], 'extra_secret' => 'y', 'plain' => 'z']);
    expect($clean)->toBe(['Password' => '[REDACTED]', 'nested' => ['NRIC' => '[REDACTED]', 'ok' => 2], 'extra_secret' => '[REDACTED]', 'plain' => 'z'])
        ->and($keys)->toBe(['Password', 'nested.NRIC', 'extra_secret']);
})->group('NFR-03');

it('scrubs key=value secrets and the sentinel from free text', function (): void {
    $s = new SecretRedactor;
    expect($s->scrub('password=abc123 token: xyz apikey="k"'))->toBe('password=[SECRET-REDACTED] token: [SECRET-REDACTED] apikey="[SECRET-REDACTED]"')
        ->and($s->scrub('see '.config('infernix.secret_sentinel')))->toBe('see [SECRET-REDACTED]')
        ->and($s->scrub('nothing secret here'))->toBe('nothing secret here');
})->group('M13-05', 'UAT-08');

it('keeps already-encoded JSON valid when scrubbing a secret inside it', function (): void {
    $encoded = json_encode(['address' => json_encode(['line1' => 'portal password=hunter2', 'city' => 'KL'])]);
    $clean = (new SecretRedactor)->scrub((string) $encoded);
    expect(json_decode($clean, true, flags: JSON_THROW_ON_ERROR)['address'])->toBe('{"line1":"portal password=[SECRET-REDACTED]","city":"KL"}');
})->group('M13-05', 'UAT-08');
