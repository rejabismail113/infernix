<?php

declare(strict_types=1);

namespace App\Kernel\Crm\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * Project stub creation and lookup for M03 (quotation → project conversion) without a model dependency.
 *
 * @see SRS M03-02, WF-04, UAT-06
 */
interface ProjectDirectory
{
    /**
     * @param  array{client_id: int, name: string, scope: string|null, contract_value: string, source_quotation_id: int, engagement_type?: string}  $data
     * @return int the new project id
     */
    public function createFromQuotation(array $data, User $actor): int;

    /** @return array{id: int, code: string, name: string, client_id: int, contract_value: string, lead_id: int|null, source_quotation_id: int|null, status_delivery: string, status_payment: string, scope: string|null}|null */
    public function snapshot(int $projectId): ?array;

    public function idForQuotation(int $quotationId): ?int;

    /** @return list<array{id: int, label: string, client_id: int}> */
    public function options(User $user): array;

    /** @return list<int> projects the staff member is a team member of on $date (UAT-07) */
    public function assignedProjectIds(int $staffProfileId, string $date): array;

    /** @return list<int> projects the user manages (project PM or a member with role pm) */
    public function managedProjectIds(User $user): array;

    /** @return list<int>|'*' projects the user may view ('*' = all) — M02-07 record scope */
    public function visibleProjectIds(User $user): array|string;

    /** @return array{id: int, title: string, client_id: int|null, prospect_name: string|null, estimated_value: string, engagement_type: string, stage: string}|null */
    public function leadSnapshot(int $leadId): ?array;

    /** Record that a quotation was raised for a lead (lead → quotation link, M02-02); moves the lead to "proposal". */
    public function attachQuotationToLead(int $leadId, int $quotationId, User $actor): void;

    /** @return list<array{id: int, code: string, name: string, role: string, from_date: string, to_date: string|null}> a staff member's project assignments */
    public function assignmentsForStaff(int $staffProfileId): array;

    /** @return array{id: int, project_id: int, title: string, billable_amount: string|null, status: string}|null milestone to invoice (M03-03) */
    public function milestoneSnapshot(int $milestoneId): ?array;
}
