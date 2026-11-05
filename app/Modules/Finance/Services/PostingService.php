<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Enums\JournalStatus;
use App\Modules\Finance\Events\JournalPosted;
use App\Modules\Finance\Events\JournalReversed;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Posting\JournalDraft;
use App\Modules\Finance\Posting\JournalLineDraft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * The ONLY code that writes journals (architecture test). Validates double entry, postable accounts and
 * the open period in PHP, then writes inside one transaction; Postgres triggers re-check balance,
 * immutability and the period lock. Nothing is written if any check fails (UAT-05).
 *
 * @see SRS M01-06, M01-08, WF-08, UAT-05, UAT-09
 */
final class PostingService
{
    public function __construct(private readonly NumberingService $numbering) {}

    public function post(JournalDraft $draft, User $actor): Journal
    {
        $this->validate($draft);

        return DB::transaction(function () use ($draft, $actor): Journal {
            $period = $this->openPeriodFor($draft->date);
            $journal = $this->insertDraft($draft, $period, $actor);
            $this->publish($journal, $actor);

            return $journal;
        });
    }

    /**
     * Correct a posted journal with a mirrored reversal posted in an OPEN period (default today). Works for
     * journals in locked periods — that is exactly how locked periods are corrected (UAT-09).
     */
    public function reverse(Journal $original, User $actor, string $reason, ?Carbon $date = null): Journal
    {
        if (trim($reason) === '') {
            throw new PostingRejected('A reason is required to reverse a journal.');
        }

        return DB::transaction(function () use ($original, $actor, $reason, $date): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->lockForUpdate()->findOrFail($original->id);
            if ($locked->status !== JournalStatus::Posted) {
                throw new PostingRejected("Journal {$locked->journal_no} is {$locked->status->value}; only posted journals can be reversed.");
            }
            $lines = [];
            foreach ($locked->lines()->get() as $l) {
                $lines[] = new JournalLineDraft($l->account_id, $l->credit, $l->debit, 'Reversal: '.($l->description ?? ''), $l->project_id, $l->cost_centre_id, $l->activity_id);
            }
            $draft = new JournalDraft($date ?? today(), 'reversal', $locked->id, "Reversal of {$locked->journal_no}: {$reason}", $lines);
            $this->validate($draft);
            $period = $this->openPeriodFor($draft->date);

            $reversal = $this->insertDraft($draft, $period, $actor, $locked->id);
            $this->publish($reversal, $actor, false);

            // The single allowed change to a posted journal (DB trigger enforces nothing else changes).
            DB::table('journals')->where('id', $locked->id)->update(['status' => JournalStatus::Reversed->value, 'reversed_by_id' => $reversal->id]);
            Audit::log('reverse', $locked, null, ['status' => 'posted'], ['status' => 'reversed', 'reversed_by' => $reversal->journal_no], $reason, $actor);
            DB::afterCommit(fn () => Event::dispatch(new JournalReversed($locked->id, $reversal->id)));

            return $reversal->refresh();
        });
    }

    /**
     * Store an adjusting journal for approval. Drafts carry a temporary number; the real gap-free JV number
     * is allocated only when the draft is posted (deleting a draft therefore never leaves a gap).
     */
    public function createDraft(JournalDraft $draft, User $actor): Journal
    {
        $this->validate($draft);

        return DB::transaction(function () use ($draft, $actor): Journal {
            $period = FiscalPeriod::forDate($draft->date) ?? throw new PostingRejected('No fiscal period covers '.$draft->date->toDateString().'.');
            $journal = $this->insertDraft($draft, $period, $actor, null, 'DRAFT-'.Str::upper(Str::random(10)));
            Audit::log('create', $journal, null, null, ['journal_no' => $journal->journal_no, 'memo' => $journal->memo], null, $actor);

            return $journal;
        });
    }

    /** Post an approved draft (maker-checker is checked by the caller). */
    public function postDraft(Journal $draft, User $approver): Journal
    {
        return DB::transaction(function () use ($draft, $approver): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->lockForUpdate()->findOrFail($draft->id);
            if ($locked->status !== JournalStatus::Draft) {
                throw new PostingRejected('Only draft journals can be posted.');
            }
            $lines = $locked->lines()->get()->map(fn ($l) => new JournalLineDraft($l->account_id, $l->debit, $l->credit, $l->description, $l->project_id, $l->cost_centre_id, $l->activity_id))->all();
            $this->validate(new JournalDraft($locked->posting_date, $locked->source_type, $locked->source_id, (string) $locked->memo, $lines));
            $period = $this->openPeriodFor($locked->posting_date);
            $number = $this->numbering->allocate((int) $locked->company_id, 'JOURNAL', (int) $locked->posting_date->format('Y'));
            DB::table('journals')->where('id', $locked->id)->update(['journal_no' => $number['formatted'], 'fiscal_period_id' => $period->id]);
            $this->publish($locked->refresh(), $approver);

            return $locked->refresh();
        });
    }

    /** Discard an unposted draft (it never had a real number). */
    public function discardDraft(Journal $draft, User $actor): void
    {
        DB::transaction(function () use ($draft, $actor): void {
            if ($draft->status !== JournalStatus::Draft) {
                throw new PostingRejected('Only drafts can be discarded.');
            }
            Audit::log('discard', $draft, null, ['journal_no' => $draft->journal_no], null, null, $actor);
            DB::table('journal_lines')->where('journal_id', $draft->id)->delete();
            DB::table('journals')->where('id', $draft->id)->delete();
        });
    }

    /** @throws PostingRejected */
    public function validate(JournalDraft $draft): void
    {
        if (count($draft->lines) < 2) {
            throw new PostingRejected('A journal needs at least two lines.');
        }
        $debit = Money::zero();
        $credit = Money::zero();
        $accountIds = [];
        foreach ($draft->lines as $i => $line) {
            $d = $line->debit;
            $c = $line->credit;
            if ($d->isNegative() || $c->isNegative() || ($d->isZero() === $c->isZero())) {
                throw new PostingRejected('Line '.($i + 1).' must have exactly one positive side (debit or credit).');
            }
            $debit = $debit->plus($d);
            $credit = $credit->plus($c);
            $accountIds[] = $line->accountId;
        }
        if (! $debit->isEqualTo($credit)) {
            throw new PostingRejected('Journal is not balanced: debits '.$debit->getAmount().' ≠ credits '.$credit->getAmount().'.');
        }
        $accounts = ChartAccount::query()->whereIn('id', array_unique($accountIds))->get()->keyBy('id');
        foreach (array_unique($accountIds) as $id) {
            $a = $accounts->get($id);
            if ($a === null || ! $a->active || ! $a->is_postable) {
                throw new PostingRejected('Account '.($a?->label() ?? "#{$id}").' is not an active postable account.');
            }
        }
    }

    public function openPeriodFor(Carbon $date): FiscalPeriod
    {
        $period = FiscalPeriod::forDate($date)
            ?? throw new PostingRejected('No fiscal period covers '.$date->toDateString().'; create the fiscal year first.');
        if ($period->isLocked()) {
            throw new PostingRejected("The fiscal period for {$date->toDateString()} is locked. Post a reversal or an approved adjusting journal in an open period (UAT-09).");
        }

        return $period;
    }

    private function insertDraft(JournalDraft $draft, FiscalPeriod $period, User $actor, ?int $reversalOf = null, ?string $tempNo = null): Journal
    {
        $journalNo = $tempNo ?? $this->numbering->allocate((int) $actor->company_id, 'JOURNAL', (int) $draft->date->format('Y'))['formatted'];
        $id = DB::table('journals')->insertGetId([
            'company_id' => $actor->company_id,
            'journal_no' => $journalNo,
            'journal_date' => $draft->date->toDateString(),
            'posting_date' => $draft->date->toDateString(),
            'fiscal_period_id' => $period->id,
            'source_type' => $draft->sourceType,
            'source_id' => $draft->sourceId,
            'status' => JournalStatus::Draft->value,
            'reversal_of_id' => $reversalOf,
            'memo' => mb_substr($draft->memo, 0, 2000),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rows = [];
        foreach ($draft->lines as $i => $l) {
            $rows[] = [
                'journal_id' => $id, 'line_no' => $i + 1, 'account_id' => $l->accountId,
                'debit' => Money::toDb($l->debit), 'credit' => Money::toDb($l->credit), 'currency' => 'MYR',
                'project_id' => $l->projectId, 'cost_centre_id' => $l->costCentreId, 'activity_id' => $l->activityId,
                'description' => $l->description !== null ? mb_substr($l->description, 0, 500) : null, 'created_at' => now(),
            ];
        }
        DB::table('journal_lines')->insert($rows);

        return Journal::query()->findOrFail($id);
    }

    private function publish(Journal $journal, User $actor, bool $audit = true): void
    {
        DB::table('journals')->where('id', $journal->id)->update([
            'status' => JournalStatus::Posted->value, 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_at' => now(),
        ]);
        // Surface the deferred balance check now, inside this transaction.
        DB::statement('SET CONSTRAINTS journals_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journals_balanced DEFERRED');
        $journal->refresh();
        if ($audit) {
            Audit::log('post', $journal, null, null, ['journal_no' => $journal->journal_no, 'source' => $journal->source_type.'#'.$journal->source_id], null, $actor);
        } else {
            Audit::log('post', $journal, null, null, ['journal_no' => $journal->journal_no, 'reversal_of' => $journal->reversal_of_id], null, $actor);
        }
        DB::afterCommit(fn () => Event::dispatch(new JournalPosted($journal->id, $journal->source_type, $journal->source_id)));
    }
}
