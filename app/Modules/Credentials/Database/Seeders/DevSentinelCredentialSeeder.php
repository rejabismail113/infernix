<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Database\Seeders;

use App\Kernel\Identity\Models\User;
use App\Modules\Credentials\Models\CredentialMetadata;
use Illuminate\Database\Seeder;

/**
 * DEV/TEST ONLY: a credential whose vault reference resolves (via VAULT_DRIVER=fake) to the FAKE sentinel secret,
 * so leak tests can prove the sentinel never reaches exports, logs, notifications or the search index (UAT-08).
 * Never runs in production. The sentinel itself is NOT stored here — only a reference.
 *
 * @see SRS M13-05, UAT-08
 */
final class DevSentinelCredentialSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }
        $owner = User::query()->whereHas('roles', fn ($r) => $r->where('code', 'OWN'))->orderBy('id')->first();
        if ($owner === null) {
            return;
        }
        CredentialMetadata::query()->firstOrCreate(['credential_no' => 'CRD-DEV-SENTINEL'], [
            'company_id' => $owner->company_id, 'system_name' => 'Leak-test sentinel (dev only)', 'category' => 'api', 'environment' => 'development', 'owner_id' => $owner->id,
            'vault_provider' => 'other', 'vault_item_reference' => 'dev/sentinel', 'sensitivity' => 'critical', 'status' => 'active', 'created_by' => $owner->id,
            'notes' => 'PLACEHOLDER fake credential: the fake vault returns the dev sentinel. Never real.',
        ]);
    }
}
