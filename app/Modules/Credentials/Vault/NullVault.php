<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Vault;

use App\Kernel\Secrets\Contracts\VaultClient;
use App\Modules\Credentials\Exceptions\CredentialRejected;

/** No vault configured: reveal is impossible; the UI shows the reference and a deep link only (M13-03). */
final class NullVault implements VaultClient
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function provider(): string
    {
        return 'none';
    }

    public function reveal(#[\SensitiveParameter] string $reference): string
    {
        throw new CredentialRejected('No vault is configured. Open the item in your vault using its reference.');
    }

    public function deepLink(string $reference): ?string
    {
        return null;
    }

    public function grant(string $reference, string $userEmail): bool
    {
        return false;
    }

    public function revoke(string $reference, string $userEmail): bool
    {
        return false;
    }
}
