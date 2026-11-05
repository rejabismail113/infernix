<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Projects\Models\Client;

/**
 * M02's side of the kernel CRM contract: client snapshots and picker options.
 *
 * @see SRS M02-01, M03-01, M03-02, UAT-06
 */
final class ClientsDirectory implements ClientDirectory
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function snapshot(int $clientId): ?array
    {
        $c = Client::query()->find($clientId);

        return $c === null ? null : [
            'id' => $c->id, 'name' => $c->name, 'registration_no' => $c->registration_no, 'tax_no' => $c->tax_no, 'email' => $c->email,
            'phone' => $c->phone, 'contact_person' => $c->contact_person, 'address' => $c->address, 'payment_terms_days' => $c->payment_terms_days,
        ];
    }

    public function options(User $user): array
    {
        if (! $this->permissions->can($user, PermissionAction::View, 'clients')) {
            return [];
        }

        return Client::query()->where('status', 'active')->orderBy('name')->limit(500)->get()
            ->map(fn (Client $c): array => ['id' => $c->id, 'label' => $c->name, 'meta' => trim(($c->registration_no ?? '').' '.($c->email ?? ''))])->values()->all();
    }
}
