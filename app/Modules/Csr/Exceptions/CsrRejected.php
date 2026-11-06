<?php

declare(strict_types=1);

namespace App\Modules\Csr\Exceptions;

use DomainException;

/** @see SRS M08-01 … M08-04 */
final class CsrRejected extends DomainException {}
