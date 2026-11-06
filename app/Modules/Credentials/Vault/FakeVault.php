<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Vault;

use App\Kernel\Secrets\Contracts\VaultClient;
use RuntimeException;

/**
 * DEV/TEST ONLY. Every reveal returns the fake sentinel (config infernix.secret_sentinel), so leak tests can
 * grep logs / exports / notifications / the search index for it (UAT-08). Refuses to exist in production.
 *
 * @see SRS M13-05, UAT-08
 */
final class FakeVault implements VaultClient
{
    public function __construct()
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The fake vault cannot be used in production.');
        }
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function reveal(#[\SensitiveParameter] string $reference): string
    {
        return (string) config('infernix.secret_sentinel');
    }

    public function deepLink(string $reference): ?string
    {
        return null;
    }

    public function grant(string $reference, string $userEmail): bool
    {
        return true;
    }

    public function revoke(string $reference, string $userEmail): bool
    {
        return true;
    }
}
