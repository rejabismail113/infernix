<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Posting\JournalDraft;
use App\Modules\Finance\Posting\JournalLineDraft;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Finance\Services\PostingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @see SRS M01-06, UAT-05, §6.3, CLAUDE.md rule 2 */
function draftOf(array $pairs, ?Carbon $date = null): JournalDraft
{
    $lines = [];
    foreach ($pairs as [$key, $side, $amount]) {
        $lines[] = $side === 'D' ? JournalLineDraft::debit(account($key)->id, Money::myr($amount)) : JournalLineDraft::credit(account($key)->id, Money::myr($amount));
    }

    return new JournalDraft($date ?? today(), 'manual', null, 'test', $lines);
}

it('UAT-05: an unbalanced journal is rejected and nothing is written (not even a number)', function (): void {
    $before = ['journals' => Journal::query()->count(), 'lines' => DB::table('journal_lines')->count(), 'next' => DB::table('numbering_sequences')->where('document_type', 'JOURNAL')->where('fiscal_year', 2026)->value('next_number')];

    expect(fn () => app(PostingService::class)->post(draftOf([['general_expense', 'D', '100.00'], ['1010', 'C', '99.99']]), ownerUser()))
        ->toThrow(PostingRejected::class, 'not balanced');

    expect(Journal::query()->count())->toBe($before['journals'])
        ->and(DB::table('journal_lines')->count())->toBe($before['lines'])
        ->and(DB::table('numbering_sequences')->where('document_type', 'JOURNAL')->where('fiscal_year', 2026)->value('next_number'))->toBe($before['next']);
})->group('UAT-05', 'M01-06');

it('rejects one-line journals, two-sided lines, zero lines and non-postable accounts', function (): void {
    $svc = app(PostingService::class);
    $owner = ownerUser();
    expect(fn () => $svc->post(draftOf([['general_expense', 'D', '10.00']]), $owner))->toThrow(PostingRejected::class, 'at least two');
    expect(fn () => $svc->post(new JournalDraft(today(), 'manual', null, 'x', [new JournalLineDraft(account('general_expense')->id, Money::myr('5'), Money::myr('5')), JournalLineDraft::credit(account('1010')->id, Money::myr('0'))]), $owner))->toThrow(PostingRejected::class, 'exactly one positive side');
    expect(fn () => $svc->post(draftOf([['5000', 'D', '10.00'], ['1010', 'C', '10.00']]), $owner))->toThrow(PostingRejected::class, 'not an active postable');
})->group('UAT-05', 'M01-06');

it('the database itself refuses an unbalanced posted journal (deferred constraint trigger)', function (): void {
    $owner = ownerUser();
    $period = FiscalPeriod::forDate(today());
    expect(fn () => DB::transaction(function () use ($owner, $period): void {
        $id = DB::table('journals')->insertGetId(['company_id' => $owner->company_id, 'journal_no' => 'X-1', 'journal_date' => today(), 'posting_date' => today(), 'fiscal_period_id' => $period->id, 'source_type' => 'manual', 'status' => 'draft', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journal_lines')->insert([['journal_id' => $id, 'line_no' => 1, 'account_id' => account('general_expense')->id, 'debit' => '10', 'credit' => '0'], ['journal_id' => $id, 'line_no' => 2, 'account_id' => account('1010')->id, 'debit' => '0', 'credit' => '9']]);
        DB::table('journals')->where('id', $id)->update(['status' => 'posted']);
        DB::statement('SET CONSTRAINTS journals_balanced IMMEDIATE');
    }))->toThrow(QueryException::class, 'not balanced');
    expect(DB::table('journals')->where('journal_no', 'X-1')->exists())->toBeFalse();
})->group('UAT-05', 'M01-06');

it('posted journals and their lines are immutable at DB level, except posted → reversed + reversed_by_id', function (): void {
    $j = app(PostingService::class)->post(draftOf([['general_expense', 'D', '50.00'], ['1010', 'C', '50.00']]), ownerUser());
    $line = $j->lines()->first();
    $try = fn (callable $fn) => fn () => DB::transaction($fn);

    expect($try(fn () => DB::table('journals')->where('id', $j->id)->update(['memo' => 'tampered'])))->toThrow(QueryException::class, 'immutable');
    expect($try(fn () => DB::table('journals')->where('id', $j->id)->delete()))->toThrow(QueryException::class, 'cannot be deleted');
    expect($try(fn () => DB::table('journal_lines')->where('id', $line->id)->update(['debit' => '51'])))->toThrow(QueryException::class, 'cannot be update');
    expect($try(fn () => DB::table('journal_lines')->where('id', $line->id)->delete()))->toThrow(QueryException::class, 'cannot be delete');
    expect($try(fn () => DB::table('journal_lines')->insert(['journal_id' => $j->id, 'line_no' => 9, 'account_id' => account('1010')->id, 'debit' => '1', 'credit' => '0'])))->toThrow(QueryException::class, 'cannot be insert');
    // status change WITHOUT reversed_by_id, or together with another column → refused
    expect($try(fn () => DB::table('journals')->where('id', $j->id)->update(['status' => 'reversed'])))->toThrow(QueryException::class, 'immutable');
    expect($try(fn () => DB::table('journals')->where('id', $j->id)->update(['status' => 'reversed', 'reversed_by_id' => $j->id, 'memo' => 'x'])))->toThrow(QueryException::class, 'immutable');
    expect($try(fn () => DB::table('journals')->where('id', $j->id)->update(['status' => 'draft'])))->toThrow(QueryException::class, 'immutable');
    expect($j->fresh()->status->value)->toBe('posted');
})->group('M01-06', 'G-10');

it('reversal creates a mirrored journal linked both ways; the original becomes reversed and frozen', function (): void {
    $owner = ownerUser();
    $j = app(PostingService::class)->post(draftOf([['general_expense', 'D', '75.50'], ['1010', 'C', '75.50']]), $owner);
    $r = app(PostingService::class)->reverse($j, $owner, 'Wrong account');
    $j->refresh();

    expect($j->status->value)->toBe('reversed')->and($j->reversed_by_id)->toBe($r->id)
        ->and($r->reversal_of_id)->toBe($j->id)->and($r->status->value)->toBe('posted')
        ->and($r->lines->map(fn ($l) => [$l->account_id, (string) $l->debit->getAmount(), (string) $l->credit->getAmount()])->all())
        ->toBe($j->lines->map(fn ($l) => [$l->account_id, (string) $l->credit->getAmount(), (string) $l->debit->getAmount()])->all())
        ->and(balanceOf('general_expense'))->toBe('0.00');
    expect(fn () => app(PostingService::class)->reverse($j, $owner, 'again'))->toThrow(PostingRejected::class, 'only posted');
    expect(fn () => DB::transaction(fn () => DB::table('journals')->where('id', $j->id)->update(['reversed_by_id' => null, 'status' => 'posted'])))->toThrow(QueryException::class, 'immutable');
})->group('M01-08', 'UAT-09');

it('randomised: 200 seeded journals — balanced always post, unbalanced always fail, ledger stays balanced', function (): void {
    mt_srand(1234);
    $svc = app(PostingService::class);
    $owner = ownerUser();
    $keys = ['general_expense', '5010', '5020', '1010', 'accounts_payable', 'owner_current', 'revenue_project', 'fixed_assets'];
    $posted = 0;
    $rejected = 0;
    for ($i = 0; $i < 200; $i++) {
        $n = mt_rand(2, 10);
        $lines = [];
        $debitCents = 0;
        $creditCents = 0;
        for ($k = 0; $k < $n - 1; $k++) {
            $cents = mt_rand(1, 1_000_000);
            $side = $k === 0 ? 'D' : (mt_rand(0, 1) ? 'D' : 'C');
            $side === 'D' ? $debitCents += $cents : $creditCents += $cents;
            $lines[] = [$keys[mt_rand(0, count($keys) - 1)], $side, Money::toDb(Money::fromDb((string) intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT)))];
        }
        $diff = $debitCents - $creditCents;
        $balanced = mt_rand(0, 3) > 0; // ~75% balanced
        $closing = $balanced ? abs($diff) : abs($diff) + mt_rand(1, 500);
        if ($closing === 0) {
            $closing = 1;
            $lines[] = [$keys[0], 'D', '0.01'];
            $diff += 1;
        }
        $lines[] = [$keys[mt_rand(0, count($keys) - 1)], $diff >= 0 ? 'C' : 'D', intdiv($closing, 100).'.'.str_pad((string) ($closing % 100), 2, '0', STR_PAD_LEFT)];
        $isBalanced = $balanced && $closing === abs($diff);
        try {
            $svc->post(draftOf($lines), $owner);
            expect($isBalanced)->toBeTrue("iteration {$i} posted although unbalanced");
            $posted++;
        } catch (PostingRejected) {
            expect($isBalanced)->toBeFalse("iteration {$i} rejected although balanced");
            $rejected++;
        }
    }
    $totals = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')->where('journals.status', '!=', 'draft')->selectRaw('SUM(debit) d, SUM(credit) c')->first();
    expect($posted)->toBeGreaterThan(100)->and($rejected)->toBeGreaterThan(20)
        ->and(Money::fromDb((string) $totals->d)->isEqualTo(Money::fromDb((string) $totals->c)))->toBeTrue()
        ->and(DB::table('journals')->where('status', 'draft')->count())->toBe(0);
    $tb = app(FinanceReports::class)->trialBalance(null, today());
    expect($tb['balanced'])->toBeTrue();
})->group('M01-06', 'UAT-05');
