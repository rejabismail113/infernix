<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => Str::random(10),
            'company_id' => Company::query()->value('id'),
        ];
    }

    /** Attach roles by code after creation (roles must be seeded). */
    public function withRoles(string ...$codes): static
    {
        return $this->afterCreating(function (User $user) use ($codes): void {
            $ids = Role::query()->whereIn('code', $codes)->pluck('id')->all();
            $user->roles()->attach($ids, ['granted_at' => now()]);
            $user->unsetRelation('roles');
        });
    }
}
