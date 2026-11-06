<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Exceptions;

use DomainException;

/** @see SRS M13-01 … M13-06 */
final class CredentialRejected extends DomainException {}
