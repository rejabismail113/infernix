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

    public function match(BankTransaction $txn, JournalLine $line, User $actor, string $type = 'manual'): BankMatch
    {
        $line->loadMissing('journal');
        $bank = $txn->bankAccount;
        if ($line->account_id !== $bank->gl_account_id) {
            throw new PostingRejected('The ledger line is not on this bank account.');
        }
        if ($line->journal->status === JournalStatus::Draft) {
            throw new PostingRejected('Only posted entries can be matched.');
        }
        $ledgerAmount = $line->debit->isPositive() ? $line->debit : $line->credit->negated();
        if (! $ledgerAmount->isEqualTo($txn->amount)) {
            throw new PostingRejected('Amounts differ: bank '.$txn->amount->getAmount().' vs ledger '.$ledgerAmount->getAmount().'.');
        }

        try {
            return DB::transaction(function () use ($txn, $line, $actor, $type): BankMatch {
                $match = BankMatch::query()->create(['bank_transaction_id' => $txn->id, 'journal_line_id' => $line->id, 'amount' => $txn->amount->abs(), 'match_type' => $type, 'matched_by' => $actor->id, 'matched_at' => now()]);
                $txn->forceFill(['status' => 'matched'])->save();

                return $match;
            });
        } catch (QueryException $e) {
            throw new PostingRejected('Already matched: a bank line and a ledger line can each be matched only once.');
        }
    }

    public function unmatch(BankTransaction $txn, User $actor, string $reason): void
    {
        $period = FiscalPeriod::forDate($txn->txn_date);
        if ($period?->isLocked()) {
            throw new PostingRejected('The period is locked; matches in locked periods cannot be changed.');
        }
        DB::transaction(function () use ($txn, $actor, $reason): void {
            $txn->match?->delete();
            $txn->forceFill(['status' => 'unmatched'])->save();
            Audit::log('unmatch', $txn, null, null, null, $reason, $actor);
        });
    }

    /**
     * Reconciliation statement: ledger vs statement balance at period end, with unmatched items both sides.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(BankAccount $bank, FiscalPeriod $period): array
    {
        $end = $period->ends_on->toDateString();
        $start = $period->starts_on->toDateString();
        $ledger = fn (string $to, bool $inclusive = true): BrickMoney => $this->ledgerBalance($bank, $to, $inclusive);
        $statementClose = $bank->opening_balance->plus(Money::fromDb((string) (BankTransaction::query()->where('bank_account_id', $bank->id)->whereDate('txn_date', '<=', $end)->sum('amount') ?: '0')));
        $statementOpen = $bank->opening_balance->plus(Money::fromDb((string) (BankTransaction::query()->where('bank_account_id', $bank->id)->whereDate('txn_date', '<', $start)->sum('amount') ?: '0')));
        $unmatchedBank = BankTransaction::query()->where('bank_account_id', $bank->id)->where('status', 'unmatched')->whereDate('txn_date', '<=', $end)->orderBy('txn_date')->get();
        $unmatchedLedger = JournalLine::query()->select('journal_lines.*')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $bank->gl_account_id)->where('journals.status', '!=', 'draft')->where('journals.posting_date', '<=', $end)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_matches')->whereColumn('bank_matches.journal_line_id', 'journal_lines.id'))
            ->with('journal')->orderBy('journals.posting_date')->get();
        $ledgerClose = $ledger($end);

        return [
            'bank' => $bank->label(),
            'period' => ['start' => $start, 'end' => $end],
            'ledger_opening' => Money::toDb($ledger($start, false)),
            'ledger_closing' => Money::toDb($ledgerClose),
            'statement_opening' => Money::toDb($statementOpen),
            'statement_closing' => Money::toDb($statementClose),
            'difference' => Money::toDb($statementClose->minus($ledgerClose)),
            'unmatched_bank' => $unmatchedBank->map(fn (BankTransaction $t) => ['id' => $t->id, 'date' => $t->txn_date->toDateString(), 'description' => $t->description, 'reference' => $t->reference, 'amount' => Money::toDb($t->amount)])->all(),
            'unmatched_ledger' => $unmatchedLedger->map(fn (JournalLine $l) => ['id' => $l->id, 'date' => $l->journal->posting_date->toDateString(), 'journal_no' => $l->journal->journal_no, 'description' => $l->description, 'amount' => Money::toDb($l->debit->minus($l->credit))])->all(),
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
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        if ($profile->has_header) {
            array_shift($lines);
        }
        $out = [];
        foreach ($lines as $n => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $cols = str_getcsv($raw);
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
