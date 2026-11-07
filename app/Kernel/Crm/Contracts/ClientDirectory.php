<?php

declare(strict_types=1);

namespace App\Kernel\Crm\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * Read access to the client master (owned by M02 Projects/CRM) for other modules — IDs and snapshots only,
 * so M03 never reaches into another module's models (docs/ARCHITECTURE.md §3).
 *
 * @see SRS M03-01, M02-01
 */
interface ClientDirectory
{
    /**
     * Point-in-time copy stored on issued documents.
     *
     * @return array{id: int, name: string, registration_no: string|null, tax_no: string|null, email: string|null, phone: string|null, contact_person: string|null, address: array<string, mixed>|null, payment_terms_days: int, client_code?: string|null, sst_no?: string|null, einvoice_id_type?: string|null, einvoice_id_no?: string|null}|null
     */
    public function snapshot(int $clientId): ?array;

    /** @return list<array{id: int, label: string, meta: string}> clients the user may pick */
    public function options(User $user): array;
}
