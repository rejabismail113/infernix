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

    /** @return array{id: int, code: string, name: string, client_id: int, contract_value: string}|null */
    public function snapshot(int $projectId): ?array;

    public function idForQuotation(int $quotationId): ?int;

    /** @return list<array{id: int, label: string, client_id: int}> */
    public function options(User $user): array;
}
