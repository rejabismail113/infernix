<?php

declare(strict_types=1);

namespace App\Modules\Finance\Posting;

use Illuminate\Support\Carbon;

/** Everything PostingService needs to write one balanced journal. */
final class JournalDraft
{
    /** @param  list<JournalLineDraft>  $lines */
    public function __construct(
        public readonly Carbon $date,
        public readonly string $sourceType,
        public readonly ?int $sourceId,
        public readonly string $memo,
        public readonly array $lines,
    ) {}
}
