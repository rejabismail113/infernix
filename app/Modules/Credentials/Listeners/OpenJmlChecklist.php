<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Listeners;

use App\Kernel\Identity\Models\User;
use App\Modules\Credentials\Services\JmlService;
use App\Modules\HumanResources\Events\StaffLifecycleChanged;

/** Staff joined / moved / left → JML checklist; leavers lose all entitlements immediately (M13-04, WF-07). */
final class OpenJmlChecklist
{
    public function __construct(private readonly JmlService $jml) {}

    public function handle(StaffLifecycleChanged $e): void
    {
        $actor = User::query()->find($e->actorId);
        if ($actor !== null) {
            $this->jml->open($e->kind, $e->staffProfileId, $e->userId, $e->detail, $actor);
        }
    }
}
