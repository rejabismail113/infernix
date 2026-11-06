<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Notifications\BusinessNotification;
use App\Kernel\Notifications\Jobs\NotifyPendingApprovals;
use App\Kernel\Notifications\NotificationService;
use App\Kernel\Notifications\PendingApprovalRegistry;
use App\Kernel\Reports\TabularFileWriter;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Jobs\NotifyOverdueInvoices;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\ReceiptService;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Projects\Models\Client;
use App\Modules\Reporting\Models\Export;
use App\Modules\Reporting\Services\MetricService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS M11-01, M11-02, M11-04, M11-05, G-05, UAT-08 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

function seedActivity(): void
{
    $invoice = issuedInvoice('10000.00');
    app(ReceiptService::class)->record(['receipt_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'amount' => '3000.00', 'method' => 'transfer',
        'client_id' => $invoice->client_id, 'allocations' => [['invoice_id' => $invoice->id, 'amount' => '3000.00']]], userWithRoles('FIN'));
    postedTransaction(); // a direct company expense
}

it('dashboard tiles equal the underlying reports (consistency)', function (): void {
    seedActivity();
    $from = today()->startOfYear();
    $to = today();
    $owner = app(MetricService::class)->owner(ownerUser(), $from, $to);
    $finance = app(MetricService::class)->finance(ownerUser(), $from, $to);
    $reports = app(FinanceReports::class);
    $pnl = $reports->profitAndLoss($from, $to);
    $cash = $reports->cashMovement($from, $to);
    $tiles = collect($owner['tiles'])->pluck('value', 'metric_id');

    expect($tiles['revenue_invoiced'])->toBe($pnl['totals']['income'])->toBe('10000.00')
        ->and($tiles['costs_incurred'])->toBe($pnl['totals']['expenses'])
        ->and($tiles['cash_received'])->toBe($cash['totals']['inflows'])->toBe('3000.00')
        ->and($tiles['cash_paid'])->toBe($cash['totals']['outflows'])
        ->and($owner['cash_balance']['total'])->toBe($cash['totals']['closing'])
        ->and($owner['outstanding_invoices']['total'])->toBe($reports->receivablesAgeing($to)['totals']['total'])->toBe('7000.00')
        ->and($owner['claims'])->toBe(['pending_approval' => $reports->claimsOutstanding()['totals']['pending_approval'], 'approved_unpaid' => $reports->claimsOutstanding()['totals']['approved_unpaid']])
        ->and($finance['unpaid_bills']['total'])->toBe($reports->payablesAgeing($to)['totals']['total'])
        ->and($finance['expense_by_category']['total'])->toBe($reports->expensesByCategory($from, $to)['totals']['total'])
        ->and(collect($finance['collections'])->last()['collected'])->toBe('3000.00');
    $monthly = collect($finance['monthly_pnl'])->firstWhere('month', $to->format('Y-m'));
    expect($monthly['income'])->toBe($reports->profitAndLoss($to->copy()->startOfMonth()->max($from), $to)['totals']['income']);
    // Every metric shown has a registry entry.
    expect(DB::table('report_metrics')->whereIn('metric_id', array_keys($owner['metrics'] + $finance['metrics']))->count())->toBe(count($owner['metrics'] + $finance['metrics']));
})->group('M11-01', 'M11-02', 'M11-04');

it('staff and project managers cannot reach the finance dashboards; auditors can read them', function (): void {
    foreach (['STF', 'PM', 'HR'] as $role) {
        actingAsSatisfied(userWithRoles($role))->get(route('reports.dashboard.owner'))->assertForbidden();
        actingAsSatisfied(userWithRoles($role))->get(route('reports.dashboard.finance'))->assertForbidden();
    }
    actingAsSatisfied(userWithRoles('FIN'))->get(route('reports.dashboard.owner'))->assertForbidden();
    actingAsSatisfied(userWithRoles('FIN'))->get(route('reports.dashboard.finance'))->assertOk();
    actingAsSatisfied(userWithRoles('AUD'))->get(route('reports.dashboard.owner'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Reporting/OwnerDashboard')->has('data.tiles', 4));
})->group('M11-05', 'NFR-02', 'UAT-01');

it('Export Centre: authorised, audited, sentinel-free, stored privately and downloadable only by the requester', function (): void {
    seedActivity();
    $sentinel = (string) config('infernix.secret_sentinel', env('SECRET_SENTINEL'));
    makeClient(['name' => 'Leaky '.$sentinel.' Sdn Bhd']);
    issuedInvoice('100.00', Client::query()->where('name', 'like', 'Leaky%')->firstOrFail());
    $fin = userWithRoles('FIN');

    $res = actingAsSatisfied($fin)->post(route('reports.exports.store'), ['report' => 'commercial.invoice-register', 'format' => 'csv', 'from' => today()->startOfYear()->toDateString(), 'to' => today()->toDateString()]);
    $export = Export::query()->latest('id')->firstOrFail();
    $res->assertRedirect(route('documents.download', ['document' => $export->document_id]));
    $doc = Document::query()->findOrFail($export->document_id);
    $content = (string) Storage::disk(config('infernix.documents.disk'))->get($doc->versions()->firstOrFail()->storage_key);
    expect($content)->toContain('INV-')->not->toContain($sentinel)
        ->and($export->user_id)->toBe($fin->id)->and($export->row_count)->toBe(2)
        ->and(AuditEvent::query()->where('action', 'export')->where('entity_type', 'commercial.invoice-register')->exists())->toBeTrue();

    // Requester can download; another FIN cannot (exports are own-scope).
    actingAsSatisfied($fin)->get(route('documents.download', ['document' => $export->document_id]))->assertRedirect();
    actingAsSatisfied(userWithRoles('FIN'))->get(route('documents.download', ['document' => $export->document_id]))->assertForbidden();

    // XLSX is a valid workbook.
    actingAsSatisfied($fin)->post(route('reports.exports.store'), ['report' => 'finance.trial-balance', 'format' => 'xlsx', 'as_of' => today()->toDateString()])->assertRedirect();
    $x = Export::query()->latest('id')->firstOrFail();
    $bytes = (string) Storage::disk(config('infernix.documents.disk'))->get(Document::query()->findOrFail($x->document_id)->versions()->firstOrFail()->storage_key);
    expect(substr($bytes, 0, 2))->toBe('PK');
})->group('M11-05', 'UAT-08', '§8.1');

it('Export Centre refuses reports the user may not export and unknown reports', function (): void {
    actingAsSatisfied(userWithRoles('STF'))->get(route('reports.exports.index'))->assertForbidden();
    actingAsSatisfied(userWithRoles('STF'))->post(route('reports.exports.store'), ['report' => 'finance.trial-balance', 'format' => 'csv'])->assertForbidden();
    actingAsSatisfied(userWithRoles('PM'))->post(route('reports.exports.store'), ['report' => 'finance.trial-balance', 'format' => 'csv'])->assertForbidden();
    actingAsSatisfied(userWithRoles('FIN'))->post(route('reports.exports.store'), ['report' => 'nope.nothing', 'format' => 'csv'])->assertNotFound();
    $pmReports = collect(actingAsSatisfied(userWithRoles('PM'))->get(route('reports.exports.index'))->assertOk()->viewData('page')['props']['reports'])->pluck('key');
    expect($pmReports->all())->toContain('projects.pipeline', 'projects.contribution')->and($pmReports->filter(fn ($k) => str_starts_with($k, 'finance.'))->all())->toBe([]);
    $aud = actingAsSatisfied(userWithRoles('AUD'))->get(route('reports.exports.index'))->assertOk();
    expect(collect($aud->viewData('page')['props']['reports'])->pluck('key')->all())->toContain('finance.trial-balance', 'commercial.invoice-register');
})->group('M11-05', 'NFR-02');

it('spreadsheet cells are scrubbed and guarded against formula injection', function (): void {
    $w = app(TabularFileWriter::class);
    expect($w->cell('=HYPERLINK("http://x")'))->toBe("'=HYPERLINK(\"http://x\")")
        ->and($w->cell('-1234.50'))->toBe('-1234.50')
        ->and($w->cell('@SUM(A1)'))->toStartWith("'")
        ->and($w->cell('x '.env('SECRET_SENTINEL').' y'))->not->toContain((string) env('SECRET_SENTINEL'));
})->group('UAT-08', 'NFR-02');

it('G-05: overdue invoices and pending approvals notify the right people once (email + bell)', function (): void {
    Notification::fake();
    $invoice = issuedInvoice('500.00');
    $fin = userWithRoles('FIN');
    $owner = ownerUser();

    Carbon::setTestNow(today()->addDays(40)->setTime(9, 0));
    (new NotifyOverdueInvoices)->handle(app(NotificationService::class));
    (new NotifyOverdueInvoices)->handle(app(NotificationService::class));
    Notification::assertSentTo($owner, BusinessNotification::class, fn ($n) => str_contains($n->title, $invoice->document_no) && $n->event === 'InvoiceOverdue');
    Notification::assertSentToTimes($fin, BusinessNotification::class, 1);
    Carbon::setTestNow();

    $maker = userWithRoles('FIN');
    $q = app(CommercialDocumentService::class)->createDraft(DocumentType::Quotation, ['client_id' => makeClient()->id, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '100']]], $maker);
    app(CommercialDocumentService::class)->submit($q, $maker);
    (new NotifyPendingApprovals)->handle(app(PendingApprovalRegistry::class), app(NotificationService::class));
    Notification::assertSentTo($owner, BusinessNotification::class, fn ($n) => $n->event === 'ApprovalPending' && str_contains($n->title, 'Quotation'));
    Notification::assertNotSentTo($maker, BusinessNotification::class, fn ($n) => $n->event === 'ApprovalPending');
    Notification::assertNotSentTo(userWithRoles('STF'), BusinessNotification::class);
})->group('G-05');

it('the in-app bell lists only the user\'s own notifications and never follows off-site links', function (): void {
    $me = userWithRoles('FIN');
    $other = userWithRoles('FIN');
    $me->notify(new BusinessNotification('InvoiceOverdue', 'Mine', 'b', url('/commercial/invoices'), ['database']));
    $other->notify(new BusinessNotification('InvoiceOverdue', 'Theirs', 'b', 'https://evil.example/phish', ['database']));
    actingAsSatisfied($me)->get(route('notifications.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('notifications', 1)->where('notifications.0.title', 'Mine'));
    $theirs = $other->notifications()->firstOrFail();
    actingAsSatisfied($me)->get(route('notifications.open', $theirs->id))->assertNotFound();
    actingAsSatisfied($other)->get(route('notifications.open', $theirs->id))->assertRedirect(route('notifications.index'));
    expect(actingAsSatisfied($me)->get('/')->viewData('page')['props']['notifications']['unread'])->toBe(1);
})->group('G-05', 'NFR-02');
