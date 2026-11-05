<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use DomainException;

/** A journal that violates double-entry or period rules. Nothing is written (UAT-05). */
final class PostingRejected extends DomainException {}
