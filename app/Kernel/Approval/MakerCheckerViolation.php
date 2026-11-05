<?php

declare(strict_types=1);

namespace App\Kernel\Approval;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The preparer tried to approve their own item without an Owner override (UAT-11). */
final class MakerCheckerViolation extends HttpException
{
    public function __construct(string $message = 'You prepared this item, so you cannot approve it. An Owner may override with a recorded reason.')
    {
        parent::__construct(403, $message);
    }
}
