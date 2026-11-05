<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * M02's side of the kernel CRM contract: project stubs for quotation conversion and project lookups.
 *
 * @see SRS M02-01, M03-01, M03-02, UAT-06
 */
final class ProjectsDirectory implements ProjectDirectory
{
    public function __construct(private readonly PermissionService $permissions, private readonly NumberingService $numbering) {}

    public function createFromQuotation(array $data, User $actor): int
    {
        return DB::transaction(function () use ($data, $actor): int {
            $existing = Project::query()->where('source_quotation_id', $data['source_quotation_id'])->value('id');
            if ($existing !== null) {
                return (int) $existing;
            }
            $code = $this->numbering->allocate((int) $actor->company_id, 'PROJECT', (int) now()->format('Y'))['formatted'];

            return Project::query()->create([
                'company_id' => $actor->company_id, 'code' => $code, 'client_id' => $data['client_id'], 'name' => $data['name'],
                'engagement_type' => $data['engagement_type'] ?? 'project', 'scope' => $data['scope'], 'contract_value' => Money::myr($data['contract_value']),
                'status_delivery' => 'active', 'status_payment' => 'unbilled', 'source_quotation_id' => $data['source_quotation_id'],
                'pm_user_id' => null, 'created_by' => $actor->id,
            ])->id;
        });
    }

    public function snapshot(int $projectId): ?array
    {
        $p = Project::query()->find($projectId);

        return $p === null ? null : ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'client_id' => $p->client_id, 'contract_value' => (string) $p->contract_value->getAmount()];
    }

    public function idForQuotation(int $quotationId): ?int
    {
        $id = Project::query()->where('source_quotation_id', $quotationId)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function options(User $user): array
    {
        $scope = $this->permissions->scopeFor($user, 'projects', PermissionAction::View);
        if ($scope === RecordScope::None) {
            return [];
        }

        return Project::query()->when($scope !== RecordScope::All, fn ($q) => $q->where(fn ($w) => $w->where('pm_user_id', $user->id)->orWhere('created_by', $user->id)))
            ->orderByDesc('id')->limit(500)->get()
            ->map(fn (Project $p): array => ['id' => $p->id, 'label' => "{$p->code} {$p->name}", 'client_id' => $p->client_id])->values()->all();
    }
}
