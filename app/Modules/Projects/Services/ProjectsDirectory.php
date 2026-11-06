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
use App\Modules\Projects\Models\Lead;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
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
            $lead = Lead::query()->where('quotation_id', $data['source_quotation_id'])->lockForUpdate()->first();
            if ($lead !== null && $lead->stage !== 'won') {
                $lead->forceFill(['stage' => 'won', 'closed_at' => now()])->save();
            }

            return Project::query()->create([
                'company_id' => $actor->company_id, 'code' => $code, 'client_id' => $data['client_id'], 'name' => $data['name'],
                'engagement_type' => $data['engagement_type'] ?? 'project', 'scope' => $data['scope'], 'contract_value' => Money::myr($data['contract_value']),
                'status_delivery' => 'active', 'status_payment' => 'unbilled', 'source_quotation_id' => $data['source_quotation_id'],
                'pm_user_id' => null, 'lead_id' => $lead?->id, 'created_by' => $actor->id,
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

        return Project::query()->when($scope !== RecordScope::All, fn ($q) => $q->whereIn('id', Project::assignedIds($user)))
            ->orderByDesc('id')->limit(500)->get()
            ->map(fn (Project $p): array => ['id' => $p->id, 'label' => "{$p->code} {$p->name}", 'client_id' => $p->client_id])->values()->all();
    }

    public function assignedProjectIds(int $staffProfileId, string $date): array
    {
        return ProjectMember::query()->where('staff_profile_id', $staffProfileId)->whereDate('from_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('to_date')->orWhereDate('to_date', '>=', $date))
            ->pluck('project_id')->map(fn ($v): int => (int) $v)->unique()->values()->all();
    }

    public function managedProjectIds(User $user): array
    {
        return Project::query()->where('pm_user_id', $user->id)
            ->orWhereIn('id', ProjectMember::query()->where('user_id', $user->id)->where('role', 'pm')->select('project_id'))
            ->pluck('id')->map(fn ($v): int => (int) $v)->all();
    }

    public function visibleProjectIds(User $user): array|string
    {
        return match ($this->permissions->scopeFor($user, 'projects', PermissionAction::View)) {
            RecordScope::All => '*',
            RecordScope::None => [],
            default => Project::assignedIds($user),
        };
    }

    public function leadSnapshot(int $leadId): ?array
    {
        $l = Lead::query()->find($leadId);

        return $l === null ? null : ['id' => $l->id, 'title' => $l->title, 'client_id' => $l->client_id, 'prospect_name' => $l->prospect_name,
            'estimated_value' => (string) $l->estimated_value->getAmount(), 'engagement_type' => $l->engagement_type, 'stage' => $l->stage];
    }

    public function attachQuotationToLead(int $leadId, int $quotationId, User $actor): void
    {
        DB::transaction(function () use ($leadId, $quotationId): void {
            $lead = Lead::query()->lockForUpdate()->findOrFail($leadId);
            if ($lead->quotation_id !== null && $lead->quotation_id !== $quotationId) {
                throw new \DomainException("Lead \"{$lead->title}\" already has a quotation.");
            }
            $lead->forceFill(['quotation_id' => $quotationId, 'stage' => in_array($lead->stage, ['new', 'qualified'], true) ? 'proposal' : $lead->stage])->save();
        });
    }
}
