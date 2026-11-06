<?php

declare(strict_types=1);

namespace App\Modules\Administration\Exceptions;

use DomainException;

/** A configuration change refused by an administration rule (shown to the user as a flash error). */
final class AdministrationRejected extends DomainException {}
