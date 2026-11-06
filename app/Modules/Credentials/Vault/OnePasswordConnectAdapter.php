<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Vault;

use App\Kernel\Secrets\Contracts\VaultClient;
use App\Modules\Credentials\Exceptions\CredentialRejected;
use Illuminate\Support\Facades\Http;

/**
 * 1Password Connect (behind FEATURE_VAULT + VAULT_DRIVER=onepassword). Token and host from the environment
 * (OP_CONNECT_TOKEN / OP_CONNECT_HOST), never the database. Reference = "<vaultId>/<itemId>" (or the item id
 * with OP_VAULT_ID set); the field with purpose PASSWORD (or the first concealed field) is returned.
 *
 * @see SRS M13-02, M13-03
 */
final class OnePasswordConnectAdapter implements VaultClient
{
    /** @param  array{connect_url: string|null, token: string|null, vault_id: string|null}  $config */
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ($this->config['connect_url'] ?? '') !== '' && ($this->config['token'] ?? '') !== '';
    }

    public function provider(): string
    {
        return 'onepassword';
    }

    public function reveal(#[\SensitiveParameter] string $reference): string
    {
        [$vault, $item] = $this->split($reference);
        $fields = Http::withToken((string) $this->config['token'])->timeout(10)
            ->get(rtrim((string) $this->config['connect_url'], '/')."/v1/vaults/{$vault}/items/{$item}")->throw()->json('fields') ?? [];
        foreach ([fn (array $f): bool => ($f['purpose'] ?? '') === 'PASSWORD', fn (array $f): bool => ($f['type'] ?? '') === 'CONCEALED'] as $match) {
            foreach ((array) $fields as $f) {
                if (is_array($f) && $match($f) && is_string($f['value'] ?? null)) {
                    return $f['value'];
                }
            }
        }
        throw new CredentialRejected('The 1Password item has no password / concealed field.');
    }

    public function deepLink(string $reference): ?string
    {
        [$vault, $item] = $this->split($reference, false);

        return $item === '' ? null : "https://start.1password.com/open/i?v={$vault}&i={$item}";
    }

    public function grant(string $reference, string $userEmail): bool
    {
        return false;
    }

    public function revoke(string $reference, string $userEmail): bool
    {
        return false;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $reference, bool $strict = true): array
    {
        $parts = explode('/', $reference, 2);
        [$vault, $item] = count($parts) === 2 ? $parts : [(string) ($this->config['vault_id'] ?? ''), $parts[0]];
        if ($strict && (! $this->isConfigured() || preg_match('/^[a-z0-9]{10,40}$/i', $vault) !== 1 || preg_match('/^[a-z0-9]{10,40}$/i', $item) !== 1)) {
            throw new CredentialRejected('The 1Password reference must be "<vaultId>/<itemId>" and Connect must be configured.');
        }

        return [$vault, $item];
    }
}
