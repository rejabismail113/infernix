<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Exceptions;

use DomainException;

/** @see SRS M09-01 … M09-04 */
final class CertificationRejected extends DomainException {}
