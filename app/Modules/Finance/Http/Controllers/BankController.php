<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\BankMatch;
use App\Modules\Finance\Models\BankMatchRule;
use App\Modules\Finance\Models\BankTransaction;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\BankService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Bank accounts, statement import, matching and reconciliation. @see SRS M01-07, WF-08 */
final class BankController
{
    use AuthorizesRequests, HandlesDomainErrors;

    public function __construct(private readonly BankService $service) {}

    public function index(): Response
    {
        $this->authorize('viewAny', BankAccount::class);

        return Inertia::render('Finance/Bank/Index', [
            'accounts' => BankAccount::query()->with(['companyAccount', 'glAccount'])->get()->map(fn (BankAccount $b): array => [
                'id' => $b->id, 'label' => $b->label(), 'gl' => $b->glAccount->label(), 'ledger_balance' => (string) $this->service->ledgerBalance($b, today()->toDateString())->getAmount(),
                'unmatched' => $b->transactions()->where('status', 'unmatched')->count(),
            ]),
        ]);
    }

    public function show(Request $request, BankAccount $bank): Response
    {
        $this->authorize('view', $bank);
        $bank->load(['companyAccount', 'glAccount']);
        $f = $request->validate(['status' => ['nullable', 'in:unmatched,matched,excluded'], 'per_page' => ['nullable', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $txns = $bank->transactions()->with('matches.journalLine.journal')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('txn_date')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 25))->withQueryString()
            ->through(fn (BankTransaction $t): array => [
                'id' => $t->id, 'txn_date' => $t->txn_date->toDateString(), 'description' => $t->description, 'reference' => $t->reference, 'amount' => (string) $t->amount->getAmount(), 'status' => $t->status,
                'matched_to' => $t->matches->isEmpty() ? null : ['journal_no' => $t->matches->map(fn ($m) => $m->journalLine->journal->journal_no)->unique()->implode(', '), 'journal_id' => $t->matches->first()->journalLine->journal_id, 'split' => $t->matches->count() > 1 || $t->matches->first()->match_group !== null && BankMatch::query()->where('match_group', $t->matches->first()->match_group)->count() > 1, 'type' => $t->matches->first()->match_type],
                'remaining' => (string) $this->service->remaining($t)->getAmount(),
                'suggestions' => $t->status === 'unmatched' ? $this->service->suggestions($t)->take(3)->map(fn (JournalLine $l): array => ['id' => $l->id, 'journal_no' => $l->journal->journal_no, 'date' => $l->journal->posting_date->toDateString(), 'description' => $l->description ?? $l->journal->memo])->values()->all() : [],
            ]);

        return Inertia::render('Finance/Bank/Show', [
            'bank' => ['id' => $bank->id, 'label' => $bank->label(), 'gl' => $bank->glAccount->label(), 'opening_balance' => (string) $bank->opening_balance->getAmount(), 'ledger_balance' => (string) $this->service->ledgerBalance($bank, today()->toDateString())->getAmount()],
            'transactions' => $txns,
            'filters' => $f,
            'profiles' => BankImportProfile::query()->where('active', true)->get(['id', 'name', 'notes']),
            'periods' => FiscalPeriod::query()->with('fiscalYear')->orderByDesc('starts_on')->limit(24)->get()->map(fn (FiscalPeriod $p): array => ['id' => $p->id, 'label' => $p->fiscalYear->year.' P'.$p->period_no]),
            'can' => ['edit' => $request->user()?->can('update', $bank) ?? false],
            'rules' => BankMatchRule::query()->where('active', true)->count(),
        ]);
    }

    /** Rule-based auto-match (M01-07). */
    public function autoMatch(Request $request, BankAccount $bank): RedirectResponse
    {
        $this->authorize('update', $bank);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($bank, $user): void {
            $n = $this->service->autoMatch($bank, $user);
            session()->flash('success', trans_choice(':count line matched automatically.|:count lines matched automatically.', $n, ['count' => $n]));
        }, '');
    }

    /** Split match: N bank lines ↔ M ledger lines with equal totals (M01-07). */
    public function matchGroup(Request $request, BankAccount $bank): RedirectResponse
    {
        $this->authorize('update', $bank);
        $data = $request->validate(['transaction_ids' => ['required', 'array', 'min:1', 'max:50'], 'transaction_ids.*' => ['integer'], 'journal_line_ids' => ['required', 'array', 'min:1', 'max:50'], 'journal_line_ids.*' => ['integer']]);
        abort_if(BankTransaction::query()->whereIn('id', $data['transaction_ids'])->where('bank_account_id', '!=', $bank->id)->exists(), 403);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->matchGroup(array_map('intval', $data['transaction_ids']), array_map('intval', $data['journal_line_ids']), $user, 'split'), __('Split match recorded.'));
    }

    /** Open ledger lines on the bank account (for the split-match picker). */
    public function ledgerLines(Request $request, BankAccount $bank): JsonResponse
    {
        $this->authorize('view', $bank);
        $q = mb_substr((string) $request->query('q', ''), 0, 60);

        return response()->json(['lines' => $this->service->openLedgerLines($bank, today()->addYear()->toDateString(), $q)->take(100)->map(fn (JournalLine $l): array => [
            'id' => $l->id, 'journal_no' => $l->journal->journal_no, 'date' => $l->journal->posting_date->toDateString(), 'description' => $l->description ?? $l->journal->memo,
            'remaining' => (string) $this->service->lineRemaining($l)->getAmount(),
        ])->values()]);
    }

    public function import(Request $request, BankAccount $bank): RedirectResponse
    {
        $this->authorize('update', $bank);
        $data = $request->validate(['file' => ['required', 'file', 'max:5120'], 'profile_id' => ['required', 'exists:bank_import_profiles,id']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($bank, $request, $data, $user): void {
            $result = $this->service->import($bank, $request->file('file'), BankImportProfile::query()->findOrFail($data['profile_id']), $user);
            session()->flash('import', $result);
        }, __('Statement imported.'));
    }

    public function storeTransaction(Request $request, BankAccount $bank): RedirectResponse
    {
        $this->authorize('update', $bank);
        $data = $request->validate(['txn_date' => ['required', 'date'], 'description' => ['required', 'string', 'max:300'], 'reference' => ['nullable', 'string', 'max:120'], 'amount' => ['required', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->addManual($bank, $data, $user), __('Bank line added.'));
    }

    public function match(Request $request, BankTransaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction->bankAccount);
        $data = $request->validate(['journal_line_id' => ['required', 'exists:journal_lines,id']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->match($transaction, JournalLine::query()->findOrFail($data['journal_line_id']), $user), __('Matched.'));
    }

    public function unmatch(Request $request, BankTransaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction->bankAccount);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->unmatch($transaction, $user, $data['reason']), __('Match removed.'));
    }

    public function reconciliation(Request $request, BankAccount $bank): Response
    {
        $this->authorize('view', $bank);
        $bank->load('companyAccount');
        $period = $request->integer('period') ? FiscalPeriod::query()->findOrFail($request->integer('period')) : (FiscalPeriod::forDate(today()) ?? abort(404));

        return Inertia::render('Finance/Bank/Reconciliation', [
            'bankId' => $bank->id, 'periodId' => $period->id, 'canExport' => app(PermissionService::class)->can($this->userOf($request), PermissionAction::Export, 'finance_reports'),
            'statement' => $this->service->reconciliation($bank, $period),
            'periods' => FiscalPeriod::query()->with('fiscalYear')->orderByDesc('starts_on')->limit(24)->get()->map(fn (FiscalPeriod $p): array => ['id' => $p->id, 'label' => $p->fiscalYear->year.' P'.$p->period_no]),
        ]);
    }

    private function userOf(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
