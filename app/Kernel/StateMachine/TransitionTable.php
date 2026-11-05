<?php

declare(strict_types=1);

namespace App\Kernel\StateMachine;

use BackedEnum;

/**
 * Explicit allowed transitions for an entity's status enum. Usage:
 *   ClaimStatus::transitions()->assert('Claim', $from, $to);
 *
 * @see SRS §5.1, CLAUDE.md rule 10
 */
final class TransitionTable
{
    /** @param  array<string, list<string>>  $allowed  from => [to, …] using enum values */
    public function __construct(private readonly array $allowed) {}

    public function allows(BackedEnum $from, BackedEnum $to): bool
    {
        return in_array((string) $to->value, $this->allowed[(string) $from->value] ?? [], true);
    }

    public function assert(string $entity, BackedEnum $from, BackedEnum $to): void
    {
        if (! $this->allows($from, $to)) {
            throw IllegalTransition::for($entity, (string) $from->value, (string) $to->value);
        }
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->allowed;
    }
}
