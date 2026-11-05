<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Kernel\Identity\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the Owner (OWN) login. Credentials come from SEED_OWNER_* env; the role itself is
 * attached in Prompt 1a once roles exist.
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

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('infernix.seed_owner.name'),
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );
    }
}
