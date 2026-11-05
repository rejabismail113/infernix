<?php

declare(strict_types=1);

namespace App\Modules\Finance\Events;

final class JournalReversed
{
    public function __construct(public readonly int $originalJournalId, public readonly int $reversalJournalId) {}
}
