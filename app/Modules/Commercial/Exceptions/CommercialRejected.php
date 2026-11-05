<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Exceptions;

use DomainException;

/** A business rule of M03 refused the operation; the message is safe to show the user. */
final class CommercialRejected extends DomainException {}
