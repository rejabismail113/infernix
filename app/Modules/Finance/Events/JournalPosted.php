<?php

declare(strict_types=1);

namespace App\Modules\Finance\Events;

/** @see docs/ARCHITECTURE.md §4 */
final class JournalPosted
{
    public function __construct(public readonly int $journalId, public readonly string $sourceType, public readonly ?int $sourceId) {}
}
