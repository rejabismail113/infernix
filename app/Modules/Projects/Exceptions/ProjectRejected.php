<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use DomainException;

/** An M02 business rule refused the operation; the message is safe to show. */
final class ProjectRejected extends DomainException {}
