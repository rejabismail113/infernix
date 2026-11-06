<?php

declare(strict_types=1);

namespace App\Modules\Activities\Exceptions;

use DomainException;

/** @see SRS M07-01 … M07-03 */
final class ActivityRejected extends DomainException {}
