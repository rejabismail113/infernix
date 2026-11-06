<?php

declare(strict_types=1);

use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\BankMatch;
use App\Modules\Finance\Models\BankMatchRule;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\BankService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS M01-07, WF-08 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

function bankLineOf(Transaction $t): JournalLine
{
    return Journal::query()->findOrFail($t->journal_id)->lines()->where('account_id', bank()->gl_account_id)->firstOrFail();
}

function expense(string $amount, string $desc = 'Supplies', array $extra = []): Transaction
{
    return postedTransaction($extra + ['lines' => [['description' => $desc, 'amount' => $amount, 'category_id' => categoryId('office')]]]);
}

it('M01-07: one bank line can settle several ledger lines and several bank lines one ledger line; the ceiling trigger caps both sides', function (): void {
    $svc = app(BankService::class);
    $a = bankLineOf(expense('60.00'));
    $b = bankLineOf(expense('40.00'));
    $one = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'Combined transfer', 'amount' => '-100.00'], ownerUser());
    expect(fn () => $svc->matchGroup([$one->id], [$a->id], ownerUser()))->toThrow(PostingRejected::class, 'Totals differ');
    $svc->matchGroup([$one->id], [$a->id, $b->id], ownerUser());
    expect($one->fresh()->status)->toBe('matched')->and(BankMatch::query()->where('bank_transaction_id', $one->id)->count())->toBe(2)
        ->and(BankMatch::query()->where('bank_transaction_id', $one->id)->distinct()->count('match_group'))->toBe(1);

    $c = bankLineOf(expense('250.00'));
    $p1 = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'Part 1', 'amount' => '-200.00'], ownerUser());
    $p2 = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'Part 2', 'amount' => '-50.00'], ownerUser());
    $svc->matchGroup([$p1->id, $p2->id], [$c->id], ownerUser());
    expect($p1->fresh()->status)->toBe('matched')->and($p2->fresh()->status)->toBe('matched')->and((string) $svc->lineRemaining($c)->getAmount())->toBe('0.00');

    // Money in cannot be paired with money out; already-matched lines are refused; DB ceiling holds.
    $in = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'Refund', 'amount' => '60.00'], ownerUser());
    expect(fn () => $svc->matchGroup([$in->id], [$a->id], ownerUser()))->toThrow(PostingRejected::class);
    expect(fn () => DB::transaction(fn () => DB::table('bank_matches')->insert(['bank_transaction_id' => $in->id, 'journal_line_id' => $a->id, 'amount' => '0.01', 'match_type' => 'manual', 'matched_by' => ownerUser()->id, 'matched_at' => now()])))
        ->toThrow(QueryException::class);

    // Unmatching a split releases the whole group.
    $svc->unmatch($p2->fresh(), ownerUser(), 'Wrong pairing');
    expect($p1->fresh()->status)->toBe('unmatched')->and($p2->fresh()->status)->toBe('unmatched')->and((string) $svc->lineRemaining($c)->getAmount())->toBe('-250.00');
})->group('M01-07', 'WF-08');

it('M01-07: rule-based auto-match uses reference regex, payee alias and date window, and never guesses between two candidates', function (): void {
    $svc = app(BankService::class);
    $byRef = expense('320.00', 'Hotel', ['description' => 'Hotel PV-2026-0042']);
    $twinA = expense('75.00', 'Grab rides');
    $twinB = expense('75.00', 'Grab rides');
    $telco = expense('158.00', 'Unifi broadband', ['description' => 'Unifi broadband Feb']);
    BankMatchRule::query()->create(['name' => 'TM alias', 'priority' => 20, 'direction' => 'out', 'date_window_days' => 5, 'payee_alias' => 'tm net', 'ledger_keyword' => 'unifi', 'active' => true, 'created_by' => ownerUser()->id]);

    $r1 = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'IBG PAYMENT', 'reference' => 'PV-2026-0042', 'amount' => '-320.00'], ownerUser());
    $r2 = $svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'GRAB', 'amount' => '-75.00'], ownerUser());
    $r3 = $svc->addManual(bank(), ['txn_date' => today()->addDays(2)->toDateString(), 'description' => 'TM NET SDN BHD DD', 'amount' => '-158.00'], ownerUser());
    $far = $svc->addManual(bank(), ['txn_date' => today()->addDays(20)->toDateString(), 'description' => 'Late charge', 'amount' => '-158.00'], ownerUser());

    expect($svc->autoMatch(bank(), ownerUser()))->toBe(2);
    expect($r1->fresh()->status)->toBe('matched')->and(BankMatch::query()->where('bank_transaction_id', $r1->id)->sole()->journal_line_id)->toBe(bankLineOf($byRef)->id)
        ->and(BankMatch::query()->where('bank_transaction_id', $r1->id)->sole()->match_type)->toBe('auto')
        ->and($r3->fresh()->status)->toBe('matched')->and(BankMatch::query()->where('bank_transaction_id', $r3->id)->sole()->journal_line_id)->toBe(bankLineOf($telco)->id)
        ->and($r2->fresh()->status)->toBe('unmatched')  // two RM 75 candidates → left for a human
        ->and($far->fresh()->status)->toBe('unmatched'); // outside every date window
    expect(BankMatch::query()->where('journal_line_id', bankLineOf($twinA)->id)->exists())->toBeFalse()->and(BankMatch::query()->where('journal_line_id', bankLineOf($twinB)->id)->exists())->toBeFalse();
})->group('M01-07');

it('M01-07 / WF-08: the period reconciliation proves statement and book balances, with unmatched ageing', function (): void {
    Carbon::setTestNow('2026-03-20 10:00:00');
    $svc = app(BankService::class);
    $period = FiscalPeriod::forDate(today());
    $matched = expense('100.00');
    $inBooksOnly = expense('30.00'); // cheque not yet presented
    $svc->match($svc->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'Paper', 'amount' => '-100.00'], ownerUser()), bankLineOf($matched), ownerUser());
    $svc->addManual(bank(), ['txn_date' => '2026-03-01', 'description' => 'Bank charge', 'amount' => '-12.50'], ownerUser()); // on statement only, 19+ days old

    $r = $svc->reconciliation(bank(), $period);
    expect($r['money_out'])->toBe('-112.5000')->and($r['statement_closing'])->toBe('-112.5000')->and($r['ledger_closing'])->toBe('-130.0000')
        ->and($r['unmatched_bank_total'])->toBe('-12.5000')->and($r['unmatched_ledger_total'])->toBe('-30.0000')
        ->and($r['adjusted_ledger'])->toBe('-112.5000')->and($r['unexplained'])->toBe('0.0000')->and($r['proven'])->toBeTrue()
        ->and($r['ageing']['bank']['8-30'])->toBe(['count' => 1, 'amount' => '-12.50'])->and($r['ageing']['ledger']['8-30']['count'])->toBe(1);
    Carbon::setTestNow(); // HTTP check at the real clock (sessions / access windows), same period
    actingAsSatisfied(ownerUser())->get(route('finance.bank.reconciliation', ['bank' => bank()->id, 'period' => $period->id]))->assertOk()
        ->assertInertia(fn ($p) => $p->where('statement.proven', true)->where('statement.unexplained', '0.0000'));
    expect($inBooksOnly->fresh())->not->toBeNull();
})->group('M01-07', 'WF-08', 'M11-02');

it('M01-07: configurable templates parse CIMB-style withdrawals / deposits, skipped rows, BOM and semicolons', function (): void {
    $cimb = BankImportProfile::query()->where('template_key', 'cimb')->firstOrFail();
    $rows = app(BankService::class)->parse("\xEF\xBB\xBFDate,Description,Ref,Withdrawal,Deposit,Balance\n05/02/2026,DUITNOW TRF,RC-2026-0001,,1500.00,1500.00\n06/02/2026,CHQ 001,,250.00,,1250.00\n", $cimb);
    expect($rows)->toHaveCount(2)->and($rows[0])->toMatchArray(['date' => '2026-02-05', 'amount' => '1500.0000', 'reference' => 'RC-2026-0001'])->and($rows[1]['amount'])->toBe('-250.0000');

    $owner = ownerUser();
    actingAsSatisfied($owner)->post(route('finance.bank.profiles.store'), ['name' => 'Semicolon bank', 'date_format' => 'Y-m-d', 'delimiter' => ';', 'skip_rows' => 2, 'has_header' => true, 'active' => true,
        'column_mapping' => ['date' => '0', 'description' => '1', 'amount' => '2']])->assertSessionHasNoErrors();
    $custom = BankImportProfile::query()->where('name', 'Semicolon bank')->firstOrFail();
    expect(app(BankService::class)->parse("Account: 123\nPeriod: Feb\ndate;desc;amount\n2026-02-07;Fee;-5.00\n", $custom)[0])->toMatchArray(['date' => '2026-02-07', 'amount' => '-5.0000']);
    actingAsSatisfied($owner)->post(route('finance.bank.profiles.store'), ['name' => 'Bad', 'date_format' => 'Y-m-d', 'delimiter' => ',', 'column_mapping' => ['date' => '0']])->assertSessionHasErrors('column_mapping');
    actingAsSatisfied($owner)->post(route('finance.bank.rules.store'), ['name' => 'Bad regex', 'priority' => 5, 'direction' => 'any', 'date_window_days' => 3, 'reference_pattern' => '/(unclosed'])->assertSessionHasErrors('reference_pattern');
    actingAsSatisfied(userWithRoles('STF'))->get(route('finance.bank.settings'))->assertForbidden();
    // Importing through the template creates statement lines once (fingerprints).
    $csv = "Date,Description,Ref,Withdrawal,Deposit,Balance\n05/02/2026,DUITNOW TRF,RC-2026-0001,,1500.00,1500.00\n";
    expect(app(BankService::class)->import(bank(), Files::csv('cimb.csv', $csv), $cimb, $owner)['imported'])->toBe(1)
        ->and(app(BankService::class)->import(bank(), Files::csv('cimb.csv', $csv), $cimb, $owner)['skipped'])->toBe(1);
})->group('M01-07');
