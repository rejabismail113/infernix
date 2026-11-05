<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Kernel\Identity\Enums\RoleCode;
use App\Kernel\Identity\Models\Role;
use Illuminate\Database\Seeder;

/** Exactly the seven SRS roles. @see SRS §2 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleCode::cases() as $code) {
            Role::query()->updateOrCreate(['code' => $code->value], [
                'name' => $code->label(), 'mfa_required' => $code->mfaRequired(), 'read_only' => $code->readOnly(),
            ]);
        }
    }
}
