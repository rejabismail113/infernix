<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Kernel\Crm\Contracts\VendorDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Procurement\Models\Vendor;

/** @see SRS M10-01, M03-05 */
final class VendorsDirectory implements VendorDirectory
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function snapshot(int $vendorId): ?array
    {
        $v = Vendor::query()->find($vendorId);

        return $v === null ? null : ['id' => $v->id, 'name' => $v->name, 'registration_no' => $v->registration_no, 'tax_no' => $v->tax_no, 'email' => $v->contacts['email'] ?? null, 'phone' => $v->contacts['phone'] ?? null];
    }

    public function options(User $user): array
    {
        if (! $this->permissions->can($user, PermissionAction::View, 'vendors')) {
            return [];
        }

        return Vendor::query()->where('status', 'active')->orderBy('name')->limit(500)->get()
            ->map(fn (Vendor $v): array => ['id' => $v->id, 'label' => $v->name, 'meta' => (string) $v->registration_no])->values()->all();
    }
}
