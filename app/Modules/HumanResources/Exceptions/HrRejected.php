<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Exceptions;

use DomainException;

/** An M04 business rule refused the operation; the message is safe to show. */
final class HrRejected extends DomainException {}
