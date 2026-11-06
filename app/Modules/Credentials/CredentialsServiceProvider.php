<?php

declare(strict_types=1);

namespace App\Modules\Credentials;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Reports\ReportTable;
use App\Kernel\Search\SearchRegistry;
use App\Kernel\Secrets\Contracts\VaultClient;
use App\Modules\Credentials\Listeners\OpenJmlChecklist;
use App\Modules\Credentials\Models\CredentialMetadata;
use App\Modules\Credentials\Models\JmlChecklist;
use App\Modules\Credentials\Policies\CredentialMetadataPolicy;
use App\Modules\Credentials\Policies\JmlChecklistPolicy;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\Credentials\Vault\BitwardenSecretsManagerAdapter;
use App\Modules\Credentials\Vault\FakeVault;
use App\Modules\Credentials\Vault\NullVault;
use App\Modules\Credentials\Vault\OnePasswordConnectAdapter;
use App\Modules\HumanResources\Events\StaffLifecycleChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M13 — Credentials & Secrets (metadata + vault references only).
 *
 * @see SRS §4 M13
 * @see docs/ARCHITECTURE.md
 */
final class CredentialsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M13';

    public function register(): void
    {
        // Real vault adapters only behind FEATURE_VAULT; the fake vault never in production (FakeVault refuses).
        $this->app->singleton(VaultClient::class, function (): VaultClient {
            $driver = (string) config('infernix.vault.driver', 'null');
            $real = (bool) config('infernix.features.vault');

            return match (true) {
                $driver === 'fake' && ! $this->app->environment('production') => new FakeVault,
                $driver === 'bitwarden' && $real => new BitwardenSecretsManagerAdapter((array) config('infernix.vault.bitwarden')),
                $driver === 'onepassword' && $real => new OnePasswordConnectAdapter((array) config('infernix.vault.onepassword')),
                default => new NullVault,
            };
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(CredentialMetadata::class, CredentialMetadataPolicy::class);
        Gate::policy(JmlChecklist::class, JmlChecklistPolicy::class);
        Event::listen(StaffLifecycleChanged::class, OpenJmlChecklist::class);

        // Indexed: number, system name, category, environment only — never usernames, URLs or references.
        $this->app->make(SearchRegistry::class)->register('credential', CredentialMetadata::class, 'Credential', fn (Model $c): string => route('credentials.credentials.show', $c->getKey()));

        $this->app->make(ReportCatalog::class)->register(new ReportDefinition('credentials.register', 'Credential register (metadata only)', 'Credentials', 'credentials',
            function (array $f, ?User $u = null): array {
                $q = $u !== null ? app(CredentialService::class)->visibleQuery($u) : CredentialMetadata::query();
                $rows = $q->orderBy('system_name')->get()->map(fn (CredentialMetadata $c): array => ['credential_no' => $c->credential_no, 'system_name' => $c->system_name, 'category' => $c->category,
                    'environment' => $c->environment, 'sensitivity' => $c->sensitivity, 'owner' => User::query()->whereKey($c->owner_id)->value('name'), 'vault' => $c->vault_provider,
                    'rotation_due' => $c->rotation_due?->toDateString(), 'expiry_date' => $c->expiry_date?->toDateString(), 'shared' => $c->is_shared_service_account ? 'yes' : 'no', 'status' => $c->status,
                    'active_grants' => (string) $c->entitlements()->whereNull('revoked_at')->count()])->values()->all();

                return ReportTable::make('Credential register', ['credential_no' => 'No', 'system_name' => 'System', 'category' => 'Category', 'environment' => 'Environment', 'sensitivity' => 'Sensitivity',
                    'owner' => 'Owner', 'vault' => 'Vault', 'rotation_due' => 'Rotation due', 'expiry_date' => 'Expires', 'shared' => 'Shared account', 'status' => 'Status', 'active_grants' => 'Active grants'], $rows, [],
                    ['Metadata only. No password, key or token is stored in Infernix Workspace; secrets live in the vault (M13-02).']);
            }, []));
    }
}
