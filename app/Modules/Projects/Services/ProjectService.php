<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Projects\Enums\LeadLostReason;
use App\Modules\Projects\Enums\LeadType;
use App\Modules\Projects\Exceptions\ProjectRejected;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Lead;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Projects created independently of invoicing (M02-01); delivery status kept separate from payment status
 * (M02-04); milestones / tasks / blockers (M02-03); team with the rate agreement version in force (WF-05);
 * closeout checklist. Leads move through the pipeline and link to their quotation (M02-02).
 *
 * @see SRS M02-01, M02-02, M02-03, M02-04, M02-07, WF-04
 */
final class ProjectService
{
    private const DELIVERY = ['lead' => ['active', 'closed'], 'active' => ['on_hold', 'completed'], 'on_hold' => ['active', 'completed'], 'completed' => ['active', 'closed']];

    public function __construct(private readonly NumberingService $numbering, private readonly StaffDirectory $staff, private readonly ClientService $clients) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): Project
    {
        return DB::transaction(function () use ($data, $actor): Project {
            $code = $this->numbering->allocate((int) $actor->company_id, 'PROJECT', (int) today()->format('Y'))['formatted'];
            $p = Project::query()->create($this->fields($data) + [
                'company_id' => $actor->company_id, 'code' => $code, 'status_delivery' => $data['status_delivery'] ?? 'active', 'status_payment' => 'unbilled', 'created_by' => $actor->id,
            ]);
            if (! empty($data['lead_id'])) {
                $lead = Lead::query()->lockForUpdate()->findOrFail($data['lead_id']);
                $lead->forceFill(['stage' => 'won', 'closed_at' => now()])->save();
                $p->forceFill(['lead_id' => $lead->id])->save();
            }

            return $p;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Project $p, array $data, User $actor): Project
    {
        $p->forceFill($this->fields($data))->save();

        return $p;
    }

    /** Delivery status only — billing/payment status is driven by M03 (M02-04). Closing needs the checklist done. */
    public function setDeliveryStatus(Project $p, string $to, User $actor, ?string $note = null): Project
    {
        if (! in_array($to, self::DELIVERY[$p->status_delivery] ?? [], true)) {
            throw new ProjectRejected("A {$p->status_delivery} project cannot move to {$to}.");
        }
        if ($to === 'closed') {
            $missing = array_diff(array_keys(Project::CLOSEOUT_ITEMS), array_keys(array_filter($p->closeout_checklist ?? [])));
            if ($missing !== []) {
                throw new ProjectRejected('Complete the closeout checklist first: '.implode(', ', array_map(fn ($k) => Project::CLOSEOUT_ITEMS[$k], $missing)).'.');
            }
        }
        $from = $p->status_delivery;
        $p->forceFill(['status_delivery' => $to, 'closed_at' => $to === 'closed' ? now() : null])->save();
        Audit::log('transition', $p, null, ['status_delivery' => $from], ['status_delivery' => $to], $note, $actor);

        return $p;
    }

    /** @param  array<string, bool>  $items */
    public function saveCloseout(Project $p, array $items, User $actor): Project
    {
        $clean = array_intersect_key(array_map(fn ($v): bool => (bool) $v, $items), Project::CLOSEOUT_ITEMS);
        $p->forceFill(['closeout_checklist' => $clean])->save();

        return $p;
    }

    /** @param  array{staff_profile_id: int, role: string, from_date: string, to_date?: string|null}  $data */
    public function addMember(Project $p, array $data, User $actor): ProjectMember
    {
        $staff = $this->staff->snapshot((int) $data['staff_profile_id']) ?? throw new ProjectRejected('Unknown staff member.');
        if ($staff['status'] !== 'active') {
            throw new ProjectRejected('Only active staff can be assigned.');
        }
        $engagement = $this->staff->engagementOn($staff['id'], $data['from_date']);
        $m = ProjectMember::query()->create([
            'project_id' => $p->id, 'staff_profile_id' => $staff['id'], 'user_id' => $staff['user_id'], 'role' => $data['role'],
            'engagement_id' => $engagement['id'] ?? null, 'from_date' => $data['from_date'], 'to_date' => $data['to_date'] ?? null,
        ]);
        if ($data['role'] === 'pm' && $staff['user_id'] !== null && $p->pm_user_id === null) {
            $p->forceFill(['pm_user_id' => $staff['user_id']])->save();
        }

        return $m;
    }

    public function endMember(ProjectMember $m, string $toDate, User $actor): ProjectMember
    {
        $m->forceFill(['to_date' => $toDate])->save();

        return $m;
    }

    /** @param  array<string, mixed>  $data */
    public function saveMilestone(Project $p, array $data, User $actor, ?Milestone $m = null): Milestone
    {
        $status = (string) ($data['status'] ?? 'pending');
        if ($status === 'blocked' && trim((string) ($data['blocker'] ?? '')) === '') {
            throw new ProjectRejected('Describe the blocker.');
        }
        $fields = [
            'title' => $data['title'], 'deliverable' => $data['deliverable'] ?? null, 'due_on' => $data['due_on'] ?? null, 'status' => $status,
            'blocker' => $status === 'blocked' ? $data['blocker'] : ($data['blocker'] ?? null),
            'billable_amount' => isset($data['billable_amount']) && $data['billable_amount'] !== '' ? Money::myr((string) $data['billable_amount']) : null,
            'completed_at' => $status === 'done' ? ($m->completed_at ?? now()) : null,
        ];
        if ($m === null) {
            return Milestone::query()->create($fields + ['project_id' => $p->id, 'sort_order' => (int) $p->milestones()->max('sort_order') + 1]);
        }
        $m->forceFill($fields)->save();

        return $m;
    }

    /** @param  array<string, mixed>  $data */
    public function saveTask(Milestone $m, array $data, User $actor, ?Task $t = null): Task
    {
        $fields = ['title' => $data['title'], 'assignee_staff_id' => $data['assignee_staff_id'] ?? null, 'due_on' => $data['due_on'] ?? null, 'status' => $data['status'] ?? 'todo'];
        if ($t === null) {
            return Task::query()->create($fields + ['milestone_id' => $m->id]);
        }
        $t->forceFill($fields)->save();

        return $t;
    }

    /** @param  array<string, mixed>  $data */
    /**
     * Create / edit a lead. The party is an existing client, a named prospect, or a NEW client registered in the
     * Client master in the same transaction (`new_client`). Choosing an existing client copies its contact details
     * when the form left them blank. A `stage` different from the current one goes through moveLead() so every
     * status change is audited and lost leads always carry a reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveLead(array $data, User $actor, ?Lead $lead = null): Lead
    {
        return DB::transaction(function () use ($data, $actor, $lead): Lead {
            if (! empty($data['new_client']) && is_array($data['new_client'])) {
                $nc = $data['new_client'];
                if (trim((string) ($nc['name'] ?? '')) === '') {
                    throw new ProjectRejected('Enter the new client\'s name.');
                }
                $client = $this->clients->save([
                    'name' => trim((string) $nc['name']), 'client_type' => 'private', 'status' => 'active', 'payment_terms_days' => 30,
                    'contacts' => [['name' => $data['contact_person'] ?? null, 'email' => $data['contact_email'] ?? null, 'phone' => $data['contact_phone'] ?? null, 'is_primary' => true]],
                ], $actor);
                $data['client_id'] = $client->id;
                $data['prospect_name'] = null;
            }
            if (empty($data['client_id']) && trim((string) ($data['prospect_name'] ?? '')) === '') {
                throw new ProjectRejected('Choose a client or enter the prospect name.');
            }
            $type = LeadType::tryFrom((string) ($data['engagement_type'] ?? 'project')) ?? throw new ProjectRejected('Unknown opportunity type.');
            $typeOther = $type === LeadType::Other ? trim((string) ($data['engagement_type_other'] ?? '')) : '';
            if ($type === LeadType::Other && $typeOther === '') {
                throw new ProjectRejected('Specify the opportunity type.');
            }
            $source = ($data['source'] ?? null) ?: null;
            $sourceOther = $source === 'Others' ? (trim((string) ($data['source_other'] ?? '')) ?: null) : null;
            $contact = ['contact_person' => $data['contact_person'] ?? null, 'contact_email' => $data['contact_email'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null];
            if (! empty($data['client_id']) && ($client = Client::query()->with('contacts')->find($data['client_id'])) !== null) {
                $primary = $client->contacts->first();
                $contact['contact_person'] = ($contact['contact_person'] ?? '') ?: ($primary->name ?? $client->contact_person);
                $contact['contact_email'] = ($contact['contact_email'] ?? '') ?: ($primary?->email ?: $client->email);
                $contact['contact_phone'] = ($contact['contact_phone'] ?? '') ?: ($primary?->phone ?: $client->phone);
            }
            $fields = $contact + [
                'client_id' => ($data['client_id'] ?? null) ?: null, 'prospect_name' => empty($data['client_id']) ? ($data['prospect_name'] ?? null) : null,
                'title' => $data['title'], 'engagement_type' => $type->value, 'engagement_type_other' => $typeOther ?: null,
                'estimated_value' => Money::myr((string) ($data['estimated_value'] ?? '0')), 'expected_close' => $data['expected_close'] ?? null,
                'next_follow_up_on' => $data['next_follow_up_on'] ?? null, 'source' => $source, 'source_other' => $sourceOther, 'notes' => $data['notes'] ?? null,
            ];
            if ($lead === null) {
                $lead = Lead::query()->create($fields + ['company_id' => $actor->company_id, 'stage' => 'new', 'owner_user_id' => $actor->id]);
            } else {
                $lead->forceFill($fields)->save();
            }
            $stage = $data['stage'] ?? null;
            if (is_string($stage) && $stage !== '' && $stage !== $lead->stage) {
                $this->moveLead($lead, $stage, $actor, $data['lost_reason'] ?? null, $data['lost_reason_code'] ?? null);
            }

            return $lead;
        });
    }

    /** Move a lead along the pipeline. Lost needs a reason: a coded reason (Others → explanation) or free text. */
    public function moveLead(Lead $lead, string $stage, User $actor, ?string $reason = null, ?string $reasonCode = null): Lead
    {
        if (! in_array($stage, Lead::STAGES, true)) {
            throw new ProjectRejected('Unknown stage.');
        }
        if (in_array($lead->stage, ['won', 'lost'], true)) {
            throw new ProjectRejected('This lead is closed.');
        }
        $reason = trim((string) $reason);
        $code = null;
        if ($stage === 'lost') {
            if ($reasonCode !== null && $reasonCode !== '') {
                $code = LeadLostReason::tryFrom($reasonCode) ?? throw new ProjectRejected('Unknown lost reason.');
                if ($code === LeadLostReason::Other && $reason === '') {
                    throw new ProjectRejected('Explain why the lead was lost.');
                }
                $reason = $code === LeadLostReason::Other ? $reason : ($reason === '' ? $code->label() : $code->label().' — '.$reason);
            } elseif ($reason === '') {
                throw new ProjectRejected('Say why the lead was lost.');
            }
        }
        $from = $lead->stage;
        $lead->forceFill([
            'stage' => $stage, 'lost_reason' => $stage === 'lost' ? $reason : null, 'lost_reason_code' => $code?->value,
            'closed_at' => in_array($stage, ['won', 'lost'], true) ? now() : null,
        ])->save();
        Audit::log('transition', $lead, null, ['stage' => $from], ['stage' => $stage], $stage === 'lost' ? $reason : null, $actor);

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $out = array_intersect_key($data, array_flip(['client_id', 'name', 'engagement_type', 'scope', 'description', 'starts_on', 'ends_on', 'billing_terms', 'pm_user_id']));
        if (array_key_exists('contract_value', $data)) {
            $out['contract_value'] = Money::myr((string) ($data['contract_value'] ?: '0'));
        }
        if (array_key_exists('cost_budget', $data)) {
            $out['cost_budget'] = $data['cost_budget'] === null || $data['cost_budget'] === '' ? null : Money::myr((string) $data['cost_budget']);
        }

        return $out;
    }
}
