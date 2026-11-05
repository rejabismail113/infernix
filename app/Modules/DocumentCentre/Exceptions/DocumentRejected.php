<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Exceptions;

use DomainException;

/** A file or document operation refused by a Document Centre rule (shown to the user as a validation error). */
final class DocumentRejected extends DomainException {}
