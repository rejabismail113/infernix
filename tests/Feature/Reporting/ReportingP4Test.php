<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Kernel\Notifications\BusinessNotification;
use App\Modules\Commercial\Services\ReceiptService;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Reporting\Models\Export;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Services\ProjectFinancials;
use App\Modules\Reporting\Services\ScheduledReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS M11-03, M11-05, M11-06, §8.1 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

const REPORTING_VIEWS = ['revenue_vs_cash', 'project_margin', 'outstanding_receivables', 'expenses_by_category', 'claims_outstanding', 'asset_register', 'tax_pack_journal_lines', 'credential_renewal'];

it('M11-06: the reporting schema has one read-only view per §8.1 report, SELECT-only grants and no PII / secret columns', function (): void {
    $views = DB::table('information_schema.views')->where('table_schema', 'reporting')->pluck('table_name')->sort()->values()->all();
    expect($views)->toBe(collect(REPORTING_VIEWS)->sort()->values()->all());
    $role = 'infernix_reporting_read';
    expect(DB::table('pg_roles')->where('rolname', $role)->value('rolcanlogin'))->toBeFalse();
    foreach (REPORTING_VIEWS as $v) {
        expect(DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, "reporting.{$v}", 'SELECT'])->ok)->toBeTrue($v)
            ->and(DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, "reporting.{$v}", 'INSERT'])->ok)->toBeFalse($v);
    }
    foreach (['journals', 'journal_lines', 'users', 'staff_profiles', 'staff_bank_details', 'credentials_metadata', 'claims'] as $t) {
        expect(DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, "public.{$t}", 'SELECT'])->ok)->toBeFalse($t);
    }
    $cols = DB::table('information_schema.columns')->where('table_schema', 'reporting')->pluck('column_name')->all();
    foreach ($cols as $c) {
        expect($c)->not->toBeIn(['nric', 'id_number', 'id_last4', 'passport', 'email', 'phone', 'bank_reference', 'account_no', 'password', 'secret', 'token', 'vault_item_reference', 'username_reference', 'address', 'description', 'memo', 'notes', 'clinical_note', 'staff_name', 'legal_name', 'payer_name', 'justification']);
    }
})->group('M11-06', 'NFR-07', 'NFR-02');

it('§8.1: reporting views agree with the application reports (project margin, revenue vs cash, receivables)', function (): void {
    $project = makeProject();
    $invoice = issuedInvoice('10000.00', $project->client()->firstOrFail(), ['project_id' => $project->id]);
    postedTransaction(['project_id' => $project->id, 'lines' => [['description' => 'Venue', 'amount' => '1500.00', 'category_id' => categoryId('rental'), 'project_id' => $project->id]]]);
    app(ReceiptService::class)->record(['receipt_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'amount' => '3000.00', 'method' => 'transfer', 'client_id' => $invoice->client_id, 'payer_name' => 'Client',
        'allocations' => [['invoice_id' => $invoice->id, 'amount' => '3000.00']]], userWithRoles('FIN'));

    $app = app(ProjectFinancials::class)->for($project->id, ownerUser())['totals'];
    $view = DB::table('reporting.project_margin')->where('project_id', $project->id)->first();
    expect((string) Money::fromDb((string) $view->net_revenue)->getAmount())->toBe($app['net_revenue'])
        ->and((string) Money::fromDb((string) $view->contribution)->getAmount())->toBe($app['contribution'])->toBe('8500.00');
    $month = DB::table('reporting.revenue_vs_cash')->where('month', today()->startOfMonth()->toDateString())->first();
    expect(Money::fromDb((string) $month->net_revenue)->getAmount()->__toString())->toBe('10000.00')
        ->and(Money::fromDb((string) $month->cash_received)->getAmount()->__toString())->toBe('3000.00');
    $ar = DB::table('reporting.outstanding_receivables')->where('invoice_id', $invoice->id)->first();
    expect(Money::fromDb((string) $ar->outstanding)->getAmount()->__toString())->toBe('7000.00');
})->group('M11-06', 'UAT-04', 'M02-05');

it('M11-03: the project dashboard shows value, status, margin and milestones — PM sees assigned projects only', function (): void {
    $pm = userWithRoles('PM');
    $mine = makeProject($pm);
    $other = makeProject();
    app(ProjectService::class)->saveMilestone($mine, ['title' => 'Module 1 delivered', 'due_on' => today()->addDays(5)->toDateString()], ownerUser());
    actingAsSatisfied($pm)->get(route('reports.projects.dashboard'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Reporting/ProjectDashboard')->has('projects', 1)->where('projects.0.id', $mine->id)
            ->where('projects.0.next_milestone.title', 'Module 1 delivered')->has('milestones', 1));
    actingAsSatisfied(ownerUser())->get(route('reports.projects.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('projects', 2));
    actingAsSatisfied(userWithRoles('APP'))->get(route('reports.projects.dashboard'))->assertForbidden();
    expect($other->id)->not->toBe($mine->id);
})->group('M11-03', 'M02-07');

it('M11-05: scheduled report emails export AS each recipient and skip anyone no longer allowed', function (): void {
    Notification::fake();
    $fin = userWithRoles('FIN');
    $stf = userWithRoles('STF');
    actingAsSatisfied($fin)->post(route('reports.schedules.store'), ['report' => 'finance.trial-balance', 'format' => 'csv', 'frequency' => 'weekly', 'day' => 1, 'recipient_ids' => [$fin->id, $stf->id]])->assertSessionHasNoErrors();
    actingAsSatisfied($stf)->post(route('reports.schedules.store'), ['report' => 'finance.trial-balance', 'format' => 'csv', 'frequency' => 'daily', 'recipient_ids' => [$stf->id]])->assertForbidden();

    $monday = Carbon::parse('next monday 07:00');
    expect(app(ScheduledReportService::class)->runDue($monday->copy()->subDay()))->toMatchArray(['schedules' => 0]);
    expect(app(ScheduledReportService::class)->runDue($monday))->toBe(['schedules' => 1, 'sent' => 1, 'skipped' => 1]);
    Notification::assertSentTo($fin, BusinessNotification::class, fn (BusinessNotification $n) => str_contains($n->url, '/documents/'));
    Notification::assertNotSentTo($stf, BusinessNotification::class);
    expect(Export::query()->where('user_id', $fin->id)->count())->toBe(1)->and(Export::query()->where('user_id', $stf->id)->count())->toBe(0);
    expect(app(ScheduledReportService::class)->runDue($monday->copy()->addHour())['schedules'])->toBe(0); // once per day
    expect(ReportSchedule::query()->sole()->last_run_at?->isSameDay($monday))->toBeTrue();
})->group('M11-05', 'G-05', 'NFR-02');
