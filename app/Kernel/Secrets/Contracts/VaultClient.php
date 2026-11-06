<?php

declare(strict_types=1);

namespace App\Kernel\Secrets\Contracts;

/**
 * The external secret vault (Bitwarden Secrets Manager / 1Password Connect). Infernix Workspace stores only a vault
 * item REFERENCE (M13-02); a secret value exists only in memory for the duration of one audited reveal and is
 * never logged, cached, persisted, indexed or exported.
 *
 * @see SRS M13-02, M13-03, WF-07
 */
interface VaultClient
{
    /** Is a real (or dev fake) vault configured? When false the UI shows the reference + deep link only. */
    public function isConfigured(): bool;

    public function provider(): string;

    /** The secret value for a reference. Throws when the vault is not configured or the item is missing. */
    public function reveal(#[\SensitiveParameter] string $reference): string;

    /** Link to the item in the vault's own UI (always safe to show). */
    public function deepLink(string $reference): ?string;

    /** Mirror an entitlement grant/revoke in the vault when it supports it. Returns false when not supported. */
    public function grant(string $reference, string $userEmail): bool;

    public function revoke(string $reference, string $userEmail): bool;
}
