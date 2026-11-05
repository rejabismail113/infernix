<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use Database\Seeders\OwnerUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 | Requirement traceability: every test carries ->group('<REQ-ID>') tags (e.g. 'G-01', 'M01-06',
 | 'UAT-05'). docs/TRACEABILITY.md maps IDs to test files. See CLAUDE.md.
 */

pest()->extend(TestCase::class)->in('Unit', 'Architecture');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** Seeded Owner login for feature tests. */
function ownerUser(): User
{
    (new OwnerUserSeeder)->run();

    return User::query()->where('email', config('infernix.seed_owner.email'))->firstOrFail();
}
