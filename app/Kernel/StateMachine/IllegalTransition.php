<?php

declare(strict_types=1);

namespace App\Kernel\StateMachine;

use DomainException;

/** A state change not allowed by the entity's transition table (CLAUDE.md rule 10). */
final class IllegalTransition extends DomainException
{
    public static function for(string $entity, string $from, string $to): self
    {
        return new self("{$entity} cannot move from {$from} to {$to}.");
    }
}
