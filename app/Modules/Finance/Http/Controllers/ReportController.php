<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\CsvExport;
use App\Modules\Finance\Http\Presenters\FinanceOptions;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Finance\Reports\TaxPackService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Finance reports (view + audited CSV export) and the tax preparation pack. @see SRS M01 outputs, §8.1, M11-05 */
final class ReportController
{
    /** @var array<string, string> */
    public const REPORTS = [
        'trial-balance' => 'Trial balance', 'general-ledger' => 'General ledger', 'profit-and-loss' => 'Profit & loss',
        'receivables-ageing' => 'Receivables ageing', 'payables-ageing' => 'Payables ageing', 'cash-movement' => 'Cash movement',
        'expenses-by-category' => 'Expenses by category', 'claims-outstanding' => 'Claims outstanding', 'owner-statement' => 'Owner capital & drawings',
    ];

    public function __construct(private readonly FinanceReports $reports) {}

    public function index(): Response
    {
        Gate::authorize('view-finance-reports');

        return Inertia::render('Finance/Reports/Index', ['reports' => self::REPORTS, 'year' => (int) today()->format('Y'), 'canExport' => Gate::allows('export-finance-reports')]);
    }

    public function show(Request $request, string $report): Response
    {
        Gate::authorize('view-finance-reports');
        abort_unless(isset(self::REPORTS[$report]), 404);
        [$filters, $data] = $this->run($request, $report);

        return Inertia::render('Finance/Reports/Show', [
            'report' => $report, 'name' => self::REPORTS[$report], 'data' => $data, 'filters' => $filters,
            'accounts' => $report === 'general-ledger' ? FinanceOptions::postableAccounts() : [],
            'canExport' => Gate::allows('export-finance-reports'),
        ]);
    }

    public function export(Request $request, string $report, CsvExport $csv): StreamedResponse
    {
        Gate::authorize('export-finance-reports');
        abort_unless(isset(self::REPORTS[$report]), 404);
        [$filters, $data] = $this->run($request, $report);
        $keys = array_map(fn (array $c) => $c['key'], $data['columns']);
        $rows = array_map(fn (array $r) => array_map(fn ($k) => $r[$k] ?? '', $keys), $data['rows']);
        foreach ($data['totals'] as $k => $v) {
            $rows[] = array_merge(['TOTAL '.$k], array_fill(0, max(0, count($keys) - 2), ''), [$v]);
        }

        return $csv->stream('finance_'.str_replace('-', '_', $report), array_map(fn (array $c) => $c['label'], $data['columns']), $rows, $filters);
    }

    public function taxPack(Request $request, TaxPackService $pack): BinaryFileResponse
    {
        Gate::authorize('export-finance-reports');
        $year = max(2020, min(2100, $request->integer('year', (int) today()->format('Y'))));
        /** @var User $user */
        $user = $request->user();

        return response()->download($pack->build($year, $user), "infernix-tax-pack-{$year}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function run(Request $request, string $report): array
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'as_of' => ['nullable', 'date'], 'basis' => ['nullable', 'in:accrual,cash'], 'account_id' => ['nullable', 'exists:chart_accounts,id']]);
        $from = Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString());
        $to = Carbon::parse($f['to'] ?? today()->toDateString());
        $asOf = Carbon::parse($f['as_of'] ?? today()->toDateString());
        $filters = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'as_of' => $asOf->toDateString(), 'basis' => $f['basis'] ?? 'accrual', 'account_id' => $f['account_id'] ?? null];

        $data = $this->reports->run($report, $filters);

        return [$filters, $data];
    }
}
