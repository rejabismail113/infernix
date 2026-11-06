<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Services;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\HumanResources\Models\StaffProfile;

/** M04's side of the kernel StaffDirectory contract — never exposes identifiers or bank data. @see SRS M04-06, M02-07 */
final class StaffDirectoryService implements StaffDirectory
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function snapshot(int $staffProfileId): ?array
    {
        $s = StaffProfile::query()->find($staffProfileId);

        return $s === null ? null : [
            'id' => $s->id, 'staff_no' => $s->staff_no, 'name' => $s->displayName(), 'legal_name' => $s->legal_name, 'user_id' => $s->user_id, 'position' => $s->position,
            'email' => $s->email, 'engagement_type' => $s->engagement_type, 'classification' => $s->classification, 'status' => $s->status,
        ];
    }

    public function options(User $user): array
    {
        if (! $this->permissions->can($user, PermissionAction::View, 'staff_profiles')) {
            return [];
        }

        return StaffProfile::query()->where('status', 'active')->orderBy('legal_name')->limit(1000)->get()
            ->map(fn (StaffProfile $s): array => ['id' => $s->id, 'label' => "{$s->displayName()} ({$s->staff_no})", 'meta' => trim(($s->position ?? '').' · '.str_replace('_', ' ', $s->engagement_type), ' ·'), 'user_id' => $s->user_id])->values()->all();
    }

    public function idForUser(User $user): ?int
    {
        $id = StaffProfile::query()->where('user_id', $user->id)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function engagementOn(int $staffProfileId, string $date): ?array
    {
        $e = StaffProfile::query()->find($staffProfileId)?->engagementOn($date);

        return $e === null ? null : ['id' => $e->id, 'version' => $e->version, 'rate_type' => $e->rate_type, 'rate_amount' => (string) $e->rate_amount->getAmount(), 'effective_from' => $e->effective_from->toDateString()];
    }
}
