<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\TabularFileWriter;
use App\Modules\Finance\Services\LedgerGateway;
use App\Modules\HumanResources\Models\PaymentBatch;
use App\Modules\HumanResources\Models\PaymentBatchLine;
use App\Modules\HumanResources\Models\Timesheet;
use App\Modules\HumanResources\Services\PaymentBatchService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Part-time payment batches (M04-04): create from calculated timesheets, contractor invoices (M04-05),
 * maker-checker approval, payout (idempotent) with a voucher or the payroll handoff file.
 *
 * @see SRS M04-03, M04-04, M04-05, M04-06, UAT-11, §5.1
 */
final class PaymentBatchController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly PaymentBatchService $service, private readonly LedgerGateway $ledger) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PaymentBatch::class);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('HR/Batches/Index', [
            'batches' => PaymentBatch::query()->withCount('lines')->orderByDesc('id')->limit(200)->get()->map(fn (PaymentBatch $b): array => self::row($b)),
            'calculated' => $user->can('create', PaymentBatch::class) ? Timesheet::query()->with('staff')->where('status', 'calculated')->orderBy('period_end')->get()->map(fn (Timesheet $t): array => TimesheetController::row($t)) : [],
            'can' => ['create' => $user->can('create', PaymentBatch::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PaymentBatch::class);
        $data = $request->validate(['timesheet_ids' => ['required', 'array', 'min:1', 'max:200'], 'timesheet_ids.*' => ['integer'], 'period_end' => ['required', 'date'], 'payout_method' => ['required', 'in:voucher,payroll_csv']]);

        return $this->attempt(function () use ($data, $request): RedirectResponse {
            $b = $this->service->create(array_map('intval', $data['timesheet_ids']), $data['period_end'], $data['payout_method'], $this->user($request));

            return redirect()->route('hr.batches.show', $b->id)->with('success', __('Batch :no created.', ['no' => $b->batch_no]));
        }, '');
    }

    public function show(Request $request, PaymentBatch $batch, DocumentStore $documents): Response
    {
        $this->authorize('view', $batch);
        $user = $this->user($request);
        $batch->load('lines.staff', 'lines.timesheet');

        return Inertia::render('HR/Batches/Show', [
            'batch' => self::row($batch) + ['paid_on' => $batch->paid_on?->toDateString(), 'voucher_document_id' => $batch->voucher_document_id, 'handoff_document_id' => $batch->handoff_document_id,
                'accrual_journal_id' => $batch->accrual_journal_id, 'payout_journal_id' => $batch->payout_journal_id, 'current_level' => $batch->current_level],
            'lines' => $batch->lines->map(fn (PaymentBatchLine $l): array => [
                'id' => $l->id, 'staff' => $l->staff->displayName(), 'staff_no' => $l->staff->staff_no, 'classification' => $l->classification, 'amount' => (string) $l->amount->getAmount(),
                'timesheet_id' => $l->timesheet_id, 'period' => $l->timesheet->period_start->toDateString().' – '.$l->timesheet->period_end->toDateString(),
                'quantity' => $l->timesheet->total_quantity, 'rate' => $l->timesheet->rate_snapshot !== null ? (string) $l->timesheet->rate_snapshot->getAmount() : null,
                'contractor_invoice_required' => $l->contractor_invoice_required, 'documents' => $documents->linkedTo('payment_batch_line', $l->id),
            ])->values(),
            'approvals' => DB::table('approval_steps as s')->join('users as u', 'u.id', '=', 's.approver_id')->where('s.entity_type', 'payment_batch')->where('s.entity_id', $batch->id)
                ->get(['s.level', 'u.name', 's.owner_override', 's.reason', 's.decided_at'])->map(fn ($r): array => (array) $r)->values(),
            'banks' => $this->ledger->bankAccountOptions(),
            'idempotencyKey' => (string) Str::uuid(),
            'can' => [
                'edit' => $batch->status === 'draft' && $user->can('update', $batch), 'submit' => $batch->status === 'draft' && $user->can('update', $batch),
                'approve' => $batch->status === 'pending_approval' && $user->can('approve', $batch), 'cancel' => in_array($batch->status, ['draft', 'pending_approval'], true) && $user->can('update', $batch),
                'pay' => $batch->status === 'approved' && $user->can('pay', $batch),
                'handoff' => $batch->status === 'paid' && $batch->payout_method === 'payroll_csv' && $user->can('pay', $batch) && Gate::allows('reveal-staff-bank'),
            ],
        ]);
    }

    public function submit(Request $request, PaymentBatch $batch): RedirectResponse
    {
        $this->authorize('update', $batch);

        return $this->attempt(fn () => $this->service->submit($batch, $this->user($request)), __('Submitted for approval.'));
    }

    public function approve(Request $request, PaymentBatch $batch): RedirectResponse
    {
        $this->authorize('approve', $batch);
        $reason = $request->validate(['override_reason' => ['nullable', 'string', 'max:500']])['override_reason'] ?? null;

        return $this->attempt(fn () => $this->service->approve($batch, $this->user($request), $reason), __('Approved.'));
    }

    public function cancel(Request $request, PaymentBatch $batch): RedirectResponse
    {
        $this->authorize('update', $batch);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->service->cancel($batch, $this->user($request), $reason), __('Batch cancelled; timesheets returned to "calculated".'));
    }

    public function pay(Request $request, PaymentBatch $batch): RedirectResponse
    {
        $this->authorize('pay', $batch);
        $data = $request->validate(['bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'], 'paid_on' => ['required', 'date']]);

        return $this->attempt(fn () => $this->service->markPaid($batch, (int) $data['bank_account_id'], $data['paid_on'], $this->user($request)), __('Batch paid.'));
    }

    /** Full payroll handoff file — reveal permission + recent re-auth, audited every time (M04-06). */
    public function handoff(Request $request, PaymentBatch $batch, TabularFileWriter $writer): BinaryFileResponse
    {
        $this->authorize('pay', $batch);
        abort_unless(Gate::allows('reveal-staff-bank') && $batch->status === 'paid', 403);
        $user = $this->user($request);
        $path = $writer->write('csv', PaymentBatchService::HANDOFF_HEADERS, $this->service->handoffRows($batch, true));
        Audit::log('reveal', $batch, null, null, ['field' => 'staff bank details', 'purpose' => 'payroll handoff download'], 'Payroll handoff', $user);
        Audit::export('payroll_handoff', ['batch_id' => $batch->id], $user);

        return response()->download($path, "payroll-handoff-{$batch->batch_no}.csv", ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store'])->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    public static function row(PaymentBatch $b): array
    {
        return ['id' => $b->id, 'batch_no' => $b->batch_no, 'period_end' => $b->period_end->toDateString(), 'status' => $b->status, 'payout_method' => $b->payout_method,
            'total_amount' => (string) $b->total_amount->getAmount(), 'lines' => (int) ($b->getAttribute('lines_count') ?? $b->lines()->count()), 'voucher_no' => $b->voucher_no];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
