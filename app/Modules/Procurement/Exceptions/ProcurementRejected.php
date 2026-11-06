<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Exceptions;

use DomainException;

/** @see SRS M10-01 … M10-05 */
final class ProcurementRejected extends DomainException {}
