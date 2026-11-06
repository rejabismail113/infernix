<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Vault;

use App\Kernel\Secrets\Contracts\VaultClient;
use App\Modules\Credentials\Exceptions\CredentialRejected;
use Illuminate\Support\Facades\Http;

/**
 * Bitwarden Secrets Manager (behind FEATURE_VAULT + VAULT_DRIVER=bitwarden). The machine-account access token
 * comes from the environment (BWS_ACCESS_TOKEN), never the database. The reference is the secret's UUID.
 * Responses are not logged; the bearer token is exchanged per reveal and kept in memory only.
 * Entitlement mirroring is not offered by the Secrets Manager API — grants stay in Infernix (audited).
 *
 * @see SRS M13-02, M13-03
 */
final class BitwardenSecretsManagerAdapter implements VaultClient
{
    /** @param  array{api_url: string, identity_url: string, access_token: string|null, web_vault_url: string}  $config */
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ($this->config['access_token'] ?? '') !== '';
    }

    public function provider(): string
    {
        return 'bitwarden';
    }

    public function reveal(#[\SensitiveParameter] string $reference): string
    {
        if (! $this->isConfigured() || preg_match('/^[0-9a-f-]{36}$/i', $reference) !== 1) {
            throw new CredentialRejected('The Bitwarden reference must be a secret id (UUID) and the vault must be configured.');
        }
        // Access token format: "0.<client_id>.<client_secret>:<encryption key>" (machine account).
        [$creds] = explode(':', (string) $this->config['access_token'], 2);
        $parts = explode('.', $creds);
        $token = Http::asForm()->timeout(10)->post(rtrim($this->config['identity_url'], '/').'/connect/token', [
            'grant_type' => 'client_credentials', 'scope' => 'api.secrets', 'client_id' => $parts[1] ?? '', 'client_secret' => $parts[2] ?? '',
        ])->throw()->json('access_token');
        $value = Http::withToken((string) $token)->timeout(10)->get(rtrim($this->config['api_url'], '/').'/secrets/'.$reference)->throw()->json('value');
        if (! is_string($value)) {
            throw new CredentialRejected('The vault item has no value.');
        }

        return $value;
    }

    public function deepLink(string $reference): string
    {
        return rtrim($this->config['web_vault_url'], '/').'/#/sm';
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
