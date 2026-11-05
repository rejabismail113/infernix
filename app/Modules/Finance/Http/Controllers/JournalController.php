<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Finance\Http\Presenters\FinanceOptions;
use App\Modules\Finance\Http\Presenters\FinancePresenter;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Posting\JournalDraft;
use App\Modules\Finance\Posting\JournalLineDraft;
use App\Modules\Finance\Services\JournalApprovalService;
use App\Modules\Finance\Services\PostingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** General journal: list, detail, adjusting drafts (approval), reversal. @see SRS M01-06, M01-08, WF-08 */
final class JournalController
{
    use AuthorizesRequests, HandlesDomainErrors;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Journal::class);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', 'in:draft,posted,reversed'], 'source_type' => ['nullable', 'string', 'max:32'], 'sort' => ['nullable', 'in:posting_date,journal_no'], 'dir' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $q = Journal::query()->with('creator:id,name')
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('journal_no', 'ilike', "%{$v}%")->orWhere('memo', 'ilike', "%{$v}%")))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->orderBy($f['sort'] ?? 'posting_date', ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        return Inertia::render('Finance/Journals/Index', [
            'journals' => $q->paginate((int) ($f['per_page'] ?? 25))->withQueryString()->through(fn (Journal $j) => FinancePresenter::journal($j)),
            'filters' => $f,
            'can' => ['create' => $request->user()?->can('create', Journal::class) ?? false],
        ]);
    }

    public function show(Request $request, Journal $journal): Response
    {
        $this->authorize('view', $journal);
        $journal->load(['lines.account', 'creator:id,name']);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Finance/Journals/Show', [
            'journal' => FinancePresenter::journal($journal, true),
            'can' => ['reverse' => $journal->status->value === 'posted' && $user->can('create', Journal::class), 'approve' => $journal->status->value === 'draft' && $user->can('approve', Journal::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Journal::class);

        return Inertia::render('Finance/Journals/Create', ['accounts' => FinanceOptions::postableAccounts()]);
    }

    public function store(Request $request, PostingService $posting): RedirectResponse
    {
        $this->authorize('create', Journal::class);
        $data = $request->validate([
            'date' => ['required', 'date'], 'memo' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:50'], 'lines.*.account_id' => ['required', 'exists:chart_accounts,id'],
            'lines.*.debit' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'], 'lines.*.credit' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $lines = array_map(fn (array $l) => new JournalLineDraft((int) $l['account_id'], Money::myr($l['debit'] ?: '0'), Money::myr($l['credit'] ?: '0'), $l['description'] ?? null), $data['lines']);
        $journal = null;

        return $this->attempt(function () use ($posting, $data, $lines, $user, &$journal) {
            $journal = $posting->createDraft(new JournalDraft(Carbon::parse($data['date']), 'manual', null, $data['memo'], $lines), $user);

            return redirect()->route('finance.journals.show', $journal);
        }, __('Adjusting journal saved as a draft. Another approver must approve it before it posts.'));
    }

    public function approve(Request $request, Journal $journal, JournalApprovalService $service): RedirectResponse
    {
        $this->authorize('approve', $journal);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $service->approve($journal, $user, $request->string('override_reason')->toString() ?: null), __('Journal approved.'));
    }

    public function reverse(Request $request, Journal $journal, PostingService $posting): RedirectResponse
    {
        $this->authorize('create', Journal::class);
        $this->authorize('view', $journal);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => redirect()->route('finance.journals.show', $posting->reverse($journal, $user, $data['reason'])), __('Journal reversed.'));
    }

    public function destroy(Request $request, Journal $journal, PostingService $posting): RedirectResponse
    {
        $this->authorize('create', Journal::class);
        abort_unless($journal->created_by === $request->user()?->id || $request->user()?->isOwner(), 403);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($posting, $journal, $user) {
            $posting->discardDraft($journal, $user);

            return redirect()->route('finance.journals.index');
        }, __('Draft discarded.'));
    }
}
