<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Reports\TaxPackService;
use App\Modules\Finance\Services\BankService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS M01-07, M01-09, UAT-08 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('imports a CSV statement once (re-import skips duplicates), suggests the matching ledger line and matches it', function (): void {
    $t = postedTransaction(['bank_reference' => 'INV-PAPER-77', 'lines' => [['description' => 'Printer paper', 'amount' => '100.00', 'category_id' => categoryId('office')]]]);
    $svc = app(BankService::class);
    $profile = BankImportProfile::query()->where('name', 'like', 'Generic%')->firstOrFail();
    $csv = "date,description,reference,amount\n".today()->format('d/m/Y').",PAPER SHOP KL,INV-PAPER-77,-100.00\n".today()->format('d/m/Y').",Bank charge,,-0.50\n";

    $first = $svc->import(bank(), Files::csv('statement.csv', $csv), $profile, ownerUser());
    $again = $svc->import(bank(), Files::csv('statement.csv', $csv), $profile, ownerUser());
    expect($first['imported'])->toBe(2)->and($again['imported'])->toBe(0)->and($again['skipped'])->toBe(2);

    $txn = bank()->transactions()->where('reference', 'INV-PAPER-77')->firstOrFail();
    $suggestions = $svc->suggestions($txn);
    $bankLine = Journal::query()->findOrFail($t->journal_id)->lines()->where('account_id', bank()->gl_account_id)->firstOrFail();
    expect($suggestions->pluck('id')->all())->toBe([$bankLine->id]);

    $svc->match($txn, $bankLine, ownerUser());
    expect($txn->fresh()->status)->toBe('matched');
})->group('M01-07');

it('prevents double matching on both sides and amount/account mismatches', function (): void {
    $t = postedTransaction();
    $svc = app(BankService::class);
    $line = Journal::query()->findOrFail($t->journal_id)->lines()->where('account_id', bank()->gl_account_id)->firstOrFail();
    $a = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'A', 'amount' => '-100.00'], ownerUser());
    $b = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'B', 'amount' => '-100.00'], ownerUser());
    $c = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'C', 'amount' => '-99.00'], ownerUser());
    $svc->match($a, $line, ownerUser());

    expect(fn () => $svc->match($b, $line, ownerUser()))->toThrow(PostingRejected::class, 'Already matched');
    expect(fn () => $svc->match($c, $line, ownerUser()))->toThrow(PostingRejected::class, 'Amounts differ');
    $other = Journal::query()->findOrFail(postedTransaction()->journal_id)->lines()->where('account_id', bank()->gl_account_id)->firstOrFail();
    expect(fn () => $svc->match($a->fresh(), $other, ownerUser()))->toThrow(PostingRejected::class, 'Already matched');
    $expenseLine = Journal::query()->findOrFail($t->journal_id)->lines()->where('account_id', '!=', bank()->gl_account_id)->firstOrFail();
    expect(fn () => $svc->match($b, $expenseLine, ownerUser()))->toThrow(PostingRejected::class, 'not on this bank account');
})->group('M01-07');

it('produces a reconciliation statement that ties out once everything is matched', function (): void {
    $t = postedTransaction();
    $svc = app(BankService::class);
    $period = FiscalPeriod::forDate(today());
    $before = $svc->reconciliation(bank(), $period);
    expect($before['ledger_closing'])->toBe('-100.0000')->and($before['statement_closing'])->toBe('0.0000')->and($before['unmatched_ledger'])->toHaveCount(1);

    $txn = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'Paper', 'amount' => '-100.00'], ownerUser());
    $svc->match($txn, Journal::query()->findOrFail($t->journal_id)->lines()->where('account_id', bank()->gl_account_id)->firstOrFail(), ownerUser());
    $after = $svc->reconciliation(bank(), $period);
    expect($after['difference'])->toBe('0.0000')->and($after['unmatched_bank'])->toBe([])->and($after['unmatched_ledger'])->toBe([]);
})->group('M01-07', 'WF-08');

it('UAT-08: the tax preparation pack contains every section, is audited, and never contains the secret sentinel', function (): void {
    $sentinel = config('infernix.secret_sentinel');
    postedTransaction(['description' => "Cloud bill token={$sentinel}", 'counterparty_name' => "AWS {$sentinel}"]);
    $path = app(TaxPackService::class)->build((int) today()->format('Y'), ownerUser());

    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];
    $all = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
        $all .= $zip->getFromIndex($i);
    }
    $zip->close();
    expect($names)->toContain('journals.csv', 'chart_of_accounts.csv', 'trial_balance.csv', 'payment_vouchers.csv', 'evidence_documents.csv', 'invoices.csv', 'bank_movement.csv', 'review_flags.csv', 'README.txt')
        ->and($all)->not->toContain($sentinel)->toContain('Printer paper')->toContain('pending accountant review')
        ->and(AuditEvent::query()->where('action', 'export')->where('entity_type', 'tax_pack')->exists())->toBeTrue();
})->group('UAT-08', 'M01-09', 'M13-05', '§8.1');
