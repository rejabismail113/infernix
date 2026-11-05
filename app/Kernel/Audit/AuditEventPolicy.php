<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Policies\ResourcePolicy;

/** @see SRS M12-04, G-06 */
final class AuditEventPolicy extends ResourcePolicy
{
    protected string $model = AuditEvent::class;
}
