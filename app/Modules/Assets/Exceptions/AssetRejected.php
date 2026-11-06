<?php

declare(strict_types=1);

namespace App\Modules\Assets\Exceptions;

use DomainException;

/** A business rule refused an asset operation (shown to the user as a form error). @see SRS M05-01 … M05-05 */
final class AssetRejected extends DomainException {}
