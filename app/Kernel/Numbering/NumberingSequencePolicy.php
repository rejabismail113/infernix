<?php

declare(strict_types=1);

namespace App\Kernel\Numbering;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Kernel\Numbering\Models\NumberingSequence;

/** @see SRS M12-05 */
final class NumberingSequencePolicy extends ResourcePolicy
{
    protected string $model = NumberingSequence::class;
}
