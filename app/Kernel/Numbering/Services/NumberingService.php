<?php

declare(strict_types=1);

namespace App\Kernel\Numbering\Services;

use App\Kernel\Numbering\Models\NumberingSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gap-free allocation. MUST be called inside the transaction that creates/issues the document so a failed
 * create rolls the number back (CLAUDE.md rule 6). The row lock (FOR UPDATE) serialises concurrent callers.
 *
 * @see SRS §6.3, G-03, M03-04, M03-08
 */
final class NumberingService
{
    /** @return array{number: int, formatted: string} */
    public function allocate(int $companyId, string $documentType, int $fiscalYear): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('NumberingService::allocate must run inside the document-creating transaction.');
        }

        /** @var NumberingSequence|null $seq */
        $seq = NumberingSequence::query()
            ->where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->where('fiscal_year', $fiscalYear)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            throw new RuntimeException("No numbering sequence for {$documentType}/{$fiscalYear}; configure it in Administration › Numbering.");
        }

        $number = $seq->next_number;
        // Direct update to avoid an audit row per allocation (the document itself is audited).
        NumberingSequence::query()->whereKey($seq->id)->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return ['number' => $number, 'formatted' => $seq->render($number)];
    }
}
