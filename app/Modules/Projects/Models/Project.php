<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Project stub (Prompt 3). M02 (Prompt 4) adds members, milestones and training via new migrations.
 *
 * @see SRS M02-01, M03-02, WF-04, UAT-06
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property int $client_id
 * @property string $name
 * @property string $engagement_type
 * @property string|null $scope
 * @property Money $contract_value
 * @property string $status_delivery
 * @property string $status_payment
 * @property int|null $source_quotation_id
 * @property int|null $pm_user_id
 * @property int|null $lead_id
 * @property string|null $billing_terms
 * @property string|null $description
 * @property Money|null $cost_budget
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $closed_at
 * @property array<string, bool>|null $closeout_checklist
 * @property int $created_by
 * @property-read Client $client
 */
final class Project extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'code', 'client_id', 'name', 'engagement_type', 'scope', 'description', 'starts_on', 'ends_on', 'contract_value', 'billing_terms', 'cost_budget', 'status_delivery', 'status_payment', 'source_quotation_id', 'lead_id', 'pm_user_id', 'closeout_checklist', 'created_by'];

    /** Closeout checklist items (PLACEHOLDER wording, editable per project). */
    public const CLOSEOUT_ITEMS = [
        'deliverables_accepted' => 'All deliverables accepted by the client',
        'final_invoice_issued' => 'Final invoice issued',
        'receipts_reconciled' => 'Receipts received or outstanding balance followed up',
        'costs_recorded' => 'All direct costs, claims and timesheets recorded',
        'documents_filed' => 'Contract, LOA, reports and evidence filed in the Document Centre',
        'lessons_learned' => 'Lessons learned noted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contract_value' => MoneyCast::class, 'cost_budget' => MoneyCast::class, 'starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime', 'closeout_checklist' => 'array'];
    }

    public static function resourceName(): string
    {
        return 'projects';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    /** PM of the project or an active team member (M02-07). */
    public function isAssignedTo(User $user): bool
    {
        return $this->pm_user_id === $user->id || $this->members()->where('user_id', $user->id)->exists();
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sort_order')->orderBy('due_on');
    }

    /** @return HasMany<TrainingSession, $this> */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class)->orderBy('session_date');
    }

    /**
     * Project ids the user may see under an "assigned" scope.
     *
     * @return list<int>
     */
    public static function assignedIds(User $user): array
    {
        return self::query()->where('pm_user_id', $user->id)->orWhere('created_by', $user->id)
            ->orWhereIn('id', ProjectMember::query()->where('user_id', $user->id)->select('project_id'))->pluck('id')->map(fn ($v): int => (int) $v)->values()->all();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
