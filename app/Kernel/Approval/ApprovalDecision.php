<?php

declare(strict_types=1);

namespace App\Kernel\Approval;

/** Who is approving, at which level, by which authority. */
final class ApprovalDecision
{
    public function __construct(
        public readonly int $level,
        public readonly bool $finalLevel,
        public readonly ?int $delegationId,
        public readonly bool $ownerOverride,
        public readonly ?string $overrideReason,
    ) {}
}
