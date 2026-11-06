<?php

declare(strict_types=1);

namespace App\Modules\Csr\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Csr\Models\CsrEvidence;

/** Restricted CSR evidence photos: csr_evidence matrix rights only (OWN, HR). @see SRS M08-04, NFR-02 */
final class CsrEvidencePolicy extends ResourcePolicy
{
    protected string $model = CsrEvidence::class;
}
