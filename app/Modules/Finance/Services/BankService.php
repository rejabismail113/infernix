<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Enums\JournalStatus;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\BankMatch;
use App\Modules\Finance\Models\BankMatchRule;
use App\Modules\Finance\Models\BankTransaction;
use App\Modules\Finance\Models\JournalLine;
use Brick\Math\BigDecimal;
use Brick\Money\Money as BrickMoney;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bank statement import (CSV with mapping profile) or manual lines, match suggestions, one-to-one matching
 * with DB uniqueness on both sides (no double matching), and a reconciliation statement per period.
 * Full rule-based / many-to-one matching arrives in Prompt 6 (P4).
 *
 * @see SRS M01-07, WF-08
 */
final class BankService
{
    public function __construct(private readonly DocumentStore $documents) {}

    /** @return array{imported: int, skipped: int, batch: string} */
    public function import(BankAccount $bank, UploadedFile $csv, BankImportProfile $profile, User $actor): array
    {
        $stored = $this->documents->attach($csv, $actor, ['title' => 'Bank statement '.now()->format('Y-m-d H:i'), 'classification' => 'confidential', 'retention_rule' => 'finance_7y'], new LinkTarget('bank_account', $bank->id, 'evidence'));
        $rows = $this->parse((string) file_get_contents((string) $csv->getRealPath()), $profile);
        $batch = (string) Str::uuid();
        $imported = 0;
        $skipped = 0;
        $seen = [];
        DB::transaction(function () use ($rows, $bank, $profile, $batch, $stored, $actor, &$imported, &$skipped, &$seen): void {
            foreach ($rows as $r) {
                $base = implode('|', [$r['date'], $r['amount'], $r['description'], $r['reference'] ?? '']);
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $fingerprint = hash('sha256', $bank->id.'|'.$base.'|'.$seen[$base]);
                $inserted = DB::table('bank_transactions')->insertOrIgnore([
                    'bank_account_id' => $bank->id, 'import_profile_id' => $profile->id, 'import_batch_id' => $batch, 'statement_document_id' => $stored->documentId,
                    'txn_date' => $r['date'], 'description' => $r['description'], 'reference' => $r['reference'] ?? null, 'amount' => $r['amount'],
                    'running_balance' => $r['balance'] ?? null, 'fingerprint' => $fingerprint, 'status' => 'unmatched', 'created_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $inserted > 0 ? $imported++ : $skipped++;
            }
        });
        Audit::log('bank_import', $bank, null, null, ['imported' => $imported, 'skipped_duplicates' => $skipped, 'profile' => $profile->name, 'document_id' => $stored->documentId], null, $actor);

        return ['imported' => $imported, 'skipped' => $skipped, 'batch' => $batch];
    }

    /**
     * @param  array{txn_date: string, description: string, reference?: string|null, amount: string}  $data
     */
    public function addManual(BankAccount $bank, array $data, User $actor): BankTransaction
    {
        $amount = BigDecimal::of($data['amount']);
        if ($amount->isZero()) {
            throw new PostingRejected('Amount cannot be zero.');
        }

        return BankTransaction::query()->create([
            'bank_account_id' => $bank->id, 'txn_date' => $data['txn_date'], 'description' => $data['description'], 'reference' => $data['reference'] ?? null,
            'amount' => (string) $amount->toScale(4), 'fingerprint' => hash('sha256', 'manual|'.Str::uuid()), 'status' => 'unmatched', 'created_by' => $actor->id,
        ]);
    }

    /**
     * Unmatched posted ledger lines on the bank's GL account with the same amount & direction, ±3 days.
     *
     * @return Collection<int, JournalLine>
     */
    public function suggestions(BankTransaction $txn, int $days = 3): Collection
    {
        $bank = $txn->bankAccount;
        $abs = Money::toDb($txn->amount->abs());
        $side = $txn->amount->isPositive() ? 'debit' : 'credit';

        return JournalLine::query()
            ->select('journal_lines.*')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $bank->gl_account_id)
            ->whereIn('journals.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->where("journal_lines.{$side}", $abs)
            ->whereBetween('journals.posting_date', [$txn->txn_date->copy()->subDays($days)->toDateString(), $txn->txn_date->copy()->addDays($days)->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_matches')->whereColumn('bank_matches.journal_line_id', 'journal_lines.id'))
            ->with('journal')
            ->get()
            ->sortBy(function (JournalLine $l) use ($txn): int {
                $dateGap = abs($l->journal->posting_date->diffInDays($txn->txn_date));
                $ref = (string) $txn->reference;
                $refHit = $ref !== '' && (str_contains((string) $l->description, $ref) || str_contains((string) $l->journal->memo, $ref)) ? 0 : 10;

                return $refHit + (int) $dateGap;
            })->values();
    }

    /** 1:1 match (kept for the simple case): a split match of one bank line and one ledger line. */
    public function match(BankTransaction $txn, JournalLine $line, User $actor, string $type = 'manual', ?int $ruleId = null): BankMatch
    {
        if ($txn->status === 'matched') {
            throw new PostingRejected('Already matched: a bank line can be matched only up to its amount.');
        }
        $line->loadMissing('journal');
        if ($line->account_id !== $txn->bankAccount->gl_account_id) {
            throw new PostingRejected('The ledger line is not on this bank account.');
        }
        $ledgerAmount = $line->debit->isPositive() ? $line->debit : $line->credit->negated();
        if (! $ledgerAmount->isEqualTo($this->remaining($txn))) {
            throw new PostingRejected('Amounts differ: bank '.$this->remaining($txn)->getAmount().' vs ledger '.$ledgerAmount->getAmount().'. Use a split match for partial amounts.');
        }

        return $this->matchGroup([$txn->id], [$line->id], $actor, $type, $ruleId)[0];
    }

    /**
     * Split match (M01-07): several bank lines ↔ one ledger line, one bank line ↔ several ledger lines, or N:M.
     * The unmatched remainders on both sides must be equal and of the same sign; amounts are allocated in date
     * order. The DB trigger caps every line at its own amount.
     *
     * @param  list<int>  $txnIds
     * @param  list<int>  $lineIds
     * @return list<BankMatch>
     */
    public function matchGroup(array $txnIds, array $lineIds, User $actor, string $type = 'manual', ?int $ruleId = null): array
    {
        if ($txnIds === [] || $lineIds === []) {
            throw new PostingRejected('Pick at least one bank line and one ledger line.');
        }

        try {
            return DB::transaction(function () use ($txnIds, $lineIds, $actor, $type, $ruleId): array {
                $txns = BankTransaction::query()->whereIn('id', $txnIds)->lockForUpdate()->with('bankAccount')->orderBy('txn_date')->orderBy('id')->get();
                $lines = JournalLine::query()->whereIn('id', $lineIds)->with('journal')->get()->sortBy(fn (JournalLine $l) => [$l->journal->posting_date->toDateString(), $l->id])->values();
                if ($txns->count() !== count(array_unique($txnIds)) || $lines->count() !== count(array_unique($lineIds))) {
                    throw new PostingRejected('Some selected lines no longer exist.');
                }
                $bank = $txns->first()->bankAccount;
                foreach ($txns as $t) {
                    if ($t->status === 'matched') {
                        throw new PostingRejected('Already matched: a bank line can be matched only up to its amount.');
                    }
                    if ($t->bank_account_id !== $bank->id || $t->status === 'excluded') {
                        throw new PostingRejected('All bank lines must be open lines of the same account.');
                    }
                    if (FiscalPeriod::forDate($t->txn_date)?->isLocked()) {
                        throw new PostingRejected('The period is locked; matches in locked periods cannot be changed.');
                    }
                }
                foreach ($lines as $l) {
                    if ($l->account_id !== $bank->gl_account_id) {
                        throw new PostingRejected('The ledger line is not on this bank account.');
                    }
                    if ($l->journal->status === JournalStatus::Draft) {
                        throw new PostingRejected('Only posted entries can be matched.');
                    }
                    if ($this->lineRemaining($l)->isZero()) {
                        throw new PostingRejected('Already matched: this ledger line is fully matched.');
                    }
                }
                $bankLeft = $txns->map(fn (BankTransaction $t) => $this->remaining($t))->all();
                $ledgerLeft = $lines->map(fn (JournalLine $l) => $this->lineRemaining($l))->all();
                $sumB = array_reduce($bankLeft, fn ($c, $m) => $c->plus($m), Money::zero());
                $sumL = array_reduce($ledgerLeft, fn ($c, $m) => $c->plus($m), Money::zero());
                $signs = array_unique(array_map(fn ($m) => $m->isPositive() ? 1 : ($m->isNegative() ? -1 : 0), [...$bankLeft, ...$ledgerLeft]));
                if (count($signs) !== 1 || $signs === [0]) {
                    throw new PostingRejected('Money in can only be matched with ledger debits, money out with ledger credits — and nothing already fully matched.');
                }
                if (! $sumB->isEqualTo($sumL)) {
                    throw new PostingRejected('Totals differ: bank '.$sumB->getAmount().' vs ledger '.$sumL->getAmount().'.');
                }
                $group = (string) Str::uuid();
                $created = [];
                $i = 0;
                $j = 0;
                while ($i < count($bankLeft) && $j < count($ledgerLeft)) {
                    $take = $bankLeft[$i]->abs()->isLessThan($ledgerLeft[$j]->abs()) ? $bankLeft[$i]->abs() : $ledgerLeft[$j]->abs();
                    $created[] = BankMatch::query()->create(['bank_transaction_id' => $txns[$i]->id, 'journal_line_id' => $lines[$j]->id, 'amount' => $take, 'match_type' => $type,
                        'matched_by' => $actor->id, 'matched_at' => now(), 'match_group' => $group, 'rule_id' => $ruleId]);
                    $sign = $bankLeft[$i]->isNegative() ? -1 : 1;
                    $bankLeft[$i] = $bankLeft[$i]->minus($sign < 0 ? $take->negated() : $take);
                    $ledgerLeft[$j] = $ledgerLeft[$j]->minus($sign < 0 ? $take->negated() : $take);
                    if ($bankLeft[$i]->isZero()) {
                        $i++;
                    }
                    if ($ledgerLeft[$j]->isZero()) {
                        $j++;
                    }
                }
                foreach ($txns as $t) {
                    $t->forceFill(['status' => $this->remaining($t->fresh() ?? $t)->isZero() ? 'matched' : 'partial'])->save();
                }
                Audit::log('bank_match', $bank, null, null, ['group' => $group, 'bank_lines' => $txns->pluck('id')->all(), 'ledger_lines' => $lines->pluck('id')->all(), 'type' => $type, 'rule_id' => $ruleId], null, $actor);

                return $created;
            });
        } catch (QueryException $e) {
            throw new PostingRejected('These lines are already matched (a line can never be matched beyond its amount).');
        }
    }

    /** Bank amount not yet matched (signed). */
    public function remaining(BankTransaction $t): BrickMoney
    {
        $used = Money::fromDb((string) (BankMatch::query()->where('bank_transaction_id', $t->id)->sum('amount') ?: '0'));

        return $t->amount->isNegative() ? $t->amount->plus($used) : $t->amount->minus($used);
    }

    /** Ledger amount on the bank account not yet matched (signed: debit = money in). */
    public function lineRemaining(JournalLine $l): BrickMoney
    {
        $used = Money::fromDb((string) (BankMatch::query()->where('journal_line_id', $l->id)->sum('amount') ?: '0'));

        return $l->debit->isPositive() ? $l->debit->minus($used) : $l->credit->negated()->plus($used);
    }

    /**
     * Rule-based auto-match: for each open bank line, try active rules by priority (then the default rule: exact
     * amount, ±3 days); match only when exactly ONE open ledger line qualifies. Returns the number of matches.
     */
    public function autoMatch(BankAccount $bank, User $actor): int
    {
        $rules = BankMatchRule::query()->where('active', true)->where(fn ($q) => $q->whereNull('bank_account_id')->orWhere('bank_account_id', $bank->id))->orderBy('priority')->get();
        $matched = 0;
        foreach (BankTransaction::query()->with('bankAccount')->where('bank_account_id', $bank->id)->where('status', 'unmatched')->orderBy('txn_date')->get() as $txn) {
            if (FiscalPeriod::forDate($txn->txn_date)?->isLocked()) {
                continue;
            }
            foreach ([...$rules->all(), null] as $rule) {
                $candidates = $this->ruleCandidates($txn, $bank, $rule);
                if ($candidates === null) {
                    continue;
                }
                if ($candidates->count() === 1) {
                    $this->match($txn, $candidates->first(), $actor, 'auto', $rule?->id);
                    $matched++;
                }
                break; // the first applicable rule decides
            }
        }
        Audit::log('bank_auto_match', $bank, null, null, ['matched' => $matched], null, $actor);

        return $matched;
    }

    /** @return Collection<int, JournalLine>|null null = the rule does not apply to this bank line */
    private function ruleCandidates(BankTransaction $txn, BankAccount $bank, ?BankMatchRule $rule): ?Collection
    {
        $text = mb_strtolower(trim($txn->description.' '.($txn->reference ?? '')));
        $captured = null;
        if ($rule !== null) {
            if (($rule->direction === 'in' && $txn->amount->isNegative()) || ($rule->direction === 'out' && $txn->amount->isPositive())) {
                return null;
            }
            if ($rule->payee_alias !== null && ! str_contains($text, mb_strtolower($rule->payee_alias))) {
                return null;
            }
            if ($rule->reference_pattern !== null) {
                if (@preg_match($rule->reference_pattern, $txn->description.' '.($txn->reference ?? ''), $m) !== 1) {
                    return null;
                }
                $captured = $m[1] ?? null;
            }
        }
        $needle = $captured ?? $rule?->ledger_keyword;

        return $this->suggestions($txn, $rule?->date_window_days ?? 3)
            ->filter(fn (JournalLine $l): bool => $needle === null || str_contains(mb_strtolower(($l->description ?? '').' '.($l->journal->memo ?? '').' '.$l->journal->journal_no), mb_strtolower($needle)))->values();
    }

    public function unmatch(BankTransaction $txn, User $actor, string $reason): void
    {
        $period = FiscalPeriod::forDate($txn->txn_date);
        if ($period?->isLocked()) {
            throw new PostingRejected('The period is locked; matches in locked periods cannot be changed.');
        }
        DB::transaction(function () use ($txn, $actor, $reason): void {
            $groups = BankMatch::query()->where('bank_transaction_id', $txn->id)->pluck('match_group')->filter()->unique()->all();
            $affected = BankMatch::query()->where(fn ($q) => $q->where('bank_transaction_id', $txn->id)->orWhereIn('match_group', $groups === [] ? ['-'] : $groups))->pluck('bank_transaction_id')->unique()->all();
            BankMatch::query()->where(fn ($q) => $q->where('bank_transaction_id', $txn->id)->orWhereIn('match_group', $groups === [] ? ['-'] : $groups))->delete();
            BankTransaction::query()->whereIn('id', $affected)->update(['status' => 'unmatched']);
            Audit::log('unmatch', $txn, null, ['groups' => array_values($groups), 'bank_lines' => array_values($affected)], null, $reason, $actor);
        });
    }

    /**
     * Unmatched ageing as at a date (both sides), buckets 0-7 / 8-30 / 31-60 / over 60 days.
     *
     * @return array{as_of: string, buckets: list<string>, bank: array<string, array{count: int, amount: string}>, ledger: array<string, array{count: int, amount: string}>}
     */
    public function ageing(BankAccount $bank, Carbon $asOf): array
    {
        $buckets = ['0-7' => 7, '8-30' => 30, '31-60' => 60, 'over 60' => PHP_INT_MAX];
        $empty = fn (): array => array_map(fn () => ['count' => 0, 'amount' => '0.00'], $buckets);
        $bucket = function (int $days) use ($buckets): string {
            foreach ($buckets as $k => $max) {
                if ($days <= $max) {
                    return $k;
                }
            }

            return 'over 60';
        };
        $add = function (array &$side, int $days, BrickMoney $amount) use ($bucket): void {
            $k = $bucket($days);
            $side[$k]['count']++;
            $side[$k]['amount'] = (string) Money::myr($side[$k]['amount'])->plus($amount)->getAmount();
        };
        $bankSide = $empty();
        foreach (BankTransaction::query()->where('bank_account_id', $bank->id)->whereIn('status', ['unmatched', 'partial'])->whereDate('txn_date', '<=', $asOf)->get() as $t) {
            $add($bankSide, (int) $t->txn_date->diffInDays($asOf), $this->remaining($t));
        }
        $ledgerSide = $empty();
        foreach ($this->openLedgerLines($bank, $asOf->toDateString()) as $l) {
            $add($ledgerSide, (int) $l->journal->posting_date->diffInDays($asOf), $this->lineRemaining($l));
        }

        return ['as_of' => $asOf->toDateString(), 'buckets' => array_keys($buckets), 'bank' => $bankSide, 'ledger' => $ledgerSide];
    }

    /** @return Collection<int, JournalLine> posted ledger lines on the bank GL with an unmatched remainder */
    public function openLedgerLines(BankAccount $bank, string $to, ?string $q = null): Collection
    {
        return JournalLine::query()->select('journal_lines.*')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $bank->gl_account_id)->where('journals.status', '!=', 'draft')->where('journals.posting_date', '<=', $to)
            ->whereRaw('(journal_lines.debit + journal_lines.credit) > COALESCE((SELECT sum(m.amount) FROM bank_matches m WHERE m.journal_line_id = journal_lines.id), 0)')
            ->when($q !== null && $q !== '', fn ($w) => $w->where(fn ($x) => $x->where('journals.journal_no', 'ilike', '%'.$q.'%')->orWhere('journal_lines.description', 'ilike', '%'.$q.'%')->orWhere('journals.memo', 'ilike', '%'.$q.'%')))
            ->with('journal')->orderBy('journals.posting_date')->limit(500)->get();
    }

    /**
     * Reconciliation statement for a period with a balance PROOF (M01-07, WF-08):
     *   statement: opening + money in − money out = closing (and the last imported running balance agrees)
     *   book:      ledger closing + bank items not yet in the books − book items not yet on the statement
     *              = adjusted balance, which must equal the statement closing (unexplained difference = 0).
     *
     * @return array<string, mixed>
     */
    public function reconciliation(BankAccount $bank, FiscalPeriod $period): array
    {
        $end = $period->ends_on->toDateString();
        $start = $period->starts_on->toDateString();
        $sum = fn ($q): BrickMoney => Money::fromDb((string) ($q->sum('amount') ?: '0'));
        $base = fn () => BankTransaction::query()->where('bank_account_id', $bank->id)->where('status', '!=', 'excluded');
        $statementOpen = $bank->opening_balance->plus($sum($base()->whereDate('txn_date', '<', $start)));
        $moneyIn = $sum($base()->whereBetween('txn_date', [$start, $end])->where('amount', '>', 0));
        $moneyOut = $sum($base()->whereBetween('txn_date', [$start, $end])->where('amount', '<', 0));
        $statementClose = $statementOpen->plus($moneyIn)->plus($moneyOut);
        $lastRunning = $base()->whereDate('txn_date', '<=', $end)->whereNotNull('running_balance')->orderByDesc('txn_date')->orderByDesc('id')->value('running_balance');

        $openBank = BankTransaction::query()->where('bank_account_id', $bank->id)->whereIn('status', ['unmatched', 'partial'])->whereDate('txn_date', '<=', $end)->orderBy('txn_date')->get()
            ->map(fn (BankTransaction $t): array => ['id' => $t->id, 'date' => $t->txn_date->toDateString(), 'description' => $t->description, 'reference' => $t->reference, 'amount' => Money::toDb($this->remaining($t)), 'status' => $t->status]);
        $openLedger = $this->openLedgerLines($bank, $end)
            ->map(fn (JournalLine $l): array => ['id' => $l->id, 'date' => $l->journal->posting_date->toDateString(), 'journal_no' => $l->journal->journal_no, 'description' => $l->description ?? $l->journal->memo, 'amount' => Money::toDb($this->lineRemaining($l))]);
        $bankItems = $openBank->reduce(fn ($c, $r) => $c->plus(Money::fromDb($r['amount'])), Money::zero());
        $ledgerItems = $openLedger->reduce(fn ($c, $r) => $c->plus(Money::fromDb($r['amount'])), Money::zero());
        $ledgerClose = $this->ledgerBalance($bank, $end);
        $adjusted = $ledgerClose->plus($bankItems)->minus($ledgerItems)->plus($bank->opening_balance);
        $unexplained = $statementClose->minus($adjusted);

        return [
            'bank' => $bank->label(),
            'period' => ['start' => $start, 'end' => $end, 'locked' => $period->isLocked()],
            'ledger_opening' => Money::toDb($this->ledgerBalance($bank, $start, false)),
            'ledger_closing' => Money::toDb($ledgerClose),
            'statement_opening' => Money::toDb($statementOpen),
            'money_in' => Money::toDb($moneyIn),
            'money_out' => Money::toDb($moneyOut),
            'statement_closing' => Money::toDb($statementClose),
            'running_balance' => $lastRunning === null ? null : Money::toDb(Money::fromDb((string) $lastRunning)),
            'running_balance_agrees' => $lastRunning === null ? null : Money::fromDb((string) $lastRunning)->isEqualTo($statementClose),
            'opening_balance_brought_forward' => Money::toDb($bank->opening_balance),
            'unmatched_bank_total' => Money::toDb($bankItems),
            'unmatched_ledger_total' => Money::toDb($ledgerItems),
            'adjusted_ledger' => Money::toDb($adjusted),
            'unexplained' => Money::toDb($unexplained),
            'proven' => $unexplained->isZero(),
            'difference' => Money::toDb($statementClose->minus($ledgerClose)),
            'unmatched_bank' => $openBank->values()->all(),
            'unmatched_ledger' => $openLedger->values()->all(),
            'ageing' => $this->ageing($bank, Carbon::parse($end)),
        ];
    }

    public function ledgerBalance(BankAccount $bank, string $to, bool $inclusive = true): BrickMoney
    {
        $row = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $bank->gl_account_id)->where('journals.status', '!=', 'draft')
            ->where('journals.posting_date', $inclusive ? '<=' : '<', $to)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal')->first();

        return Money::fromDb((string) ($row->bal ?? '0'));
    }

    /** @return list<array{date: string, description: string, reference: string|null, amount: string, balance: string|null}> */
    public function parse(string $content, BankImportProfile $profile): array
    {
        $map = $profile->column_mapping;
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content); // strip a UTF-8 BOM
        $lines = array_slice(preg_split('/\r\n|\n|\r/', trim($content)) ?: [], max(0, $profile->skip_rows ?? 0));
        if ($profile->has_header) {
            array_shift($lines);
        }
        $out = [];
        foreach ($lines as $n => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $cols = str_getcsv($raw, ($profile->delimiter ?? ',') ?: ',');
            $get = fn (string $k): ?string => isset($map[$k]) && isset($cols[(int) $map[$k]]) ? trim($cols[(int) $map[$k]]) : null;
            try {
                $date = Carbon::createFromFormat($profile->date_format, (string) $get('date'))->toDateString();
            } catch (Throwable) {
                throw new PostingRejected('Row '.($n + 1).': unreadable date "'.$get('date').'" (expected '.$profile->date_format.').');
            }
            $num = fn (?string $v): ?BigDecimal => $v === null || $v === '' ? null : BigDecimal::of(str_replace([',', 'RM', ' '], '', $v));
            try {
                $amount = isset($map['amount']) ? $num($get('amount')) : (($num($get('credit')) ?? BigDecimal::zero())->minus($num($get('debit')) ?? BigDecimal::zero()));
            } catch (Throwable) {
                throw new PostingRejected('Row '.($n + 1).': unreadable amount.');
            }
            if ($amount === null || $amount->isZero()) {
                continue;
            }
            $balance = $num($get('balance'));
            $out[] = ['date' => $date, 'description' => (string) ($get('description') ?? ''), 'reference' => $get('reference'), 'amount' => (string) $amount->toScale(4), 'balance' => $balance ? (string) $balance->toScale(4) : null];
        }

        return $out;
    }
}
