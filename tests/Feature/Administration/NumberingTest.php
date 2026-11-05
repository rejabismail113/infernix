<?php

declare(strict_types=1);

use App\Kernel\Numbering\Models\NumberingSequence;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Administration\Models\Company;
use Illuminate\Support\Facades\DB;

/** @see SRS §6.3, G-03, M03-08 */
it('formats numbers as PREFIX-YEAR-SEQ and increments sequentially inside a transaction', function (): void {
    $svc = app(NumberingService::class);
    $company = Company::current()->id;
    $a = DB::transaction(fn () => $svc->allocate($company, 'INVOICE', 2026));
    $b = DB::transaction(fn () => $svc->allocate($company, 'INVOICE', 2026));
    expect($a['formatted'])->toBe('INV-2026-0001')->and($b['formatted'])->toBe('INV-2026-0002');
})->group('§6.3', 'M03-08', 'G-03');

it('rolls the number back when the document-creating transaction fails (no gaps)', function (): void {
    $svc = app(NumberingService::class);
    $company = Company::current()->id;
    $first = DB::transaction(fn () => $svc->allocate($company, 'QUOTATION', 2026))['number'];

    try {
        DB::transaction(function () use ($svc, $company): void {
            $svc->allocate($company, 'QUOTATION', 2026);
            throw new RuntimeException('document validation failed after number allocation');
        });
    } catch (RuntimeException) {
    }

    $next = DB::transaction(fn () => $svc->allocate($company, 'QUOTATION', 2026))['number'];
    expect($next)->toBe($first + 1);
})->group('§6.3', 'M03-04');

it('never moves next_number backwards through the admin screen', function (): void {
    $seq = NumberingSequence::query()->where('document_type', 'RECEIPT')->where('fiscal_year', 2026)->firstOrFail();
    $seq->forceFill(['next_number' => 50])->save();
    actingAsSatisfied(ownerUser())->post(route('admin.numbering.store'), ['document_type' => 'RECEIPT', 'fiscal_year' => 2026, 'prefix' => 'RC', 'format' => '{prefix}-{year}-{seq}', 'padding' => 4, 'next_number' => 3])
        ->assertSessionHasNoErrors();
    expect($seq->fresh()->next_number)->toBe(50);
})->group('§6.3', 'M12-05');
