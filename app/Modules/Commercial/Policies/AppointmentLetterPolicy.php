<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Commercial\Models\AppointmentLetter;

/** Deny-by-default matrix check for AppointmentLetter. @see SRS M03-05, M03-06, NFR-02 */
final class AppointmentLetterPolicy extends ResourcePolicy
{
    protected string $model = AppointmentLetter::class;
}
