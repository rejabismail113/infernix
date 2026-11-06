<?php

declare(strict_types=1);

namespace App\Kernel\Crm\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * Vendor master (M10) for M03 vendor POs — snapshot without bank details.
 *
 * @see SRS M03-05, M10-01
 */
interface VendorDirectory
{
    /** @return array{id: int, name: string, registration_no: string|null, tax_no: string|null, email: string|null, phone: string|null}|null */
    public function snapshot(int $vendorId): ?array;

    /** @return list<array{id: int, label: string, meta: string}> */
    public function options(User $user): array;
}
