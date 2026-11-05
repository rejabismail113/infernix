<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Kernel\Identity\Enums\RoleCode;
use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\Company;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the Owner (OWN) login from SEED_OWNER_* config and attaches the OWN role.
 *
 * @see SRS G-01, §2 (OWN)
 */
final class OwnerUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('infernix.seed_owner.email');
        $password = (string) config('infernix.seed_owner.password');

        if ($password === '' || (app()->isProduction() && $password === 'ChangeMe-Infernix-2026!')) {
            throw new RuntimeException('SEED_OWNER_PASSWORD must be set to a non-default value.');
        }

        $user = User::withoutEvents(fn () => User::query()->updateOrCreate(
            ['email' => $email],
            [
                'company_id' => Company::query()->value('id'),
                'name' => (string) config('infernix.seed_owner.name'),
                'password' => $password,
                'email_verified_at' => now(),
            ],
        ));
        $owner = Role::query()->where('code', RoleCode::Owner->value)->first();
        if ($owner !== null && ! $user->roles()->where('code', RoleCode::Owner->value)->exists()) {
            $user->roles()->attach($owner->id, ['granted_at' => now()]);
        }
    }
}
