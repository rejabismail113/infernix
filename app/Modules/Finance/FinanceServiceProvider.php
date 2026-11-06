<?php

declare(strict_types=1);

namespace App\Modules\Finance;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Notifications\PendingApprovalRegistry;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Modules\Assets\Events\AssetDepreciationDue;
use App\Modules\Assets\Events\AssetDisposed;
use App\Modules\Finance\Http\Controllers\ReportController;
use App\Modules\Finance\Listeners\PostAssetDepreciation;
use App\Modules\Finance\Listeners\PostAssetDisposal;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\ClaimLine;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Policies\BankAccountPolicy;
use App\Modules\Finance\Policies\BillPolicy;
use App\Modules\Finance\Policies\ChartAccountPolicy;
use App\Modules\Finance\Policies\ClaimLinePolicy;
use App\Modules\Finance\Policies\ClaimPolicy;
use App\Modules\Finance\Policies\JournalPolicy;
use App\Modules\Finance\Policies\PaymentPolicy;
use App\Modules\Finance\Policies\TransactionPolicy;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Finance\Services\FinanceRecordsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M01 — Finance & Accounting.
 *
 * @see SRS §4 M01
 * @see docs/ARCHITECTURE.md
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M01';

    public function register(): void
    {
        $this->app->bind(FinanceRecords::class, FinanceRecordsService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');

        foreach ([
            ChartAccount::class => ChartAccountPolicy::class, Journal::class => JournalPolicy::class, Transaction::class => TransactionPolicy::class,
            Claim::class => ClaimPolicy::class, ClaimLine::class => ClaimLinePolicy::class, Bill::class => BillPolicy::class,
            Payment::class => PaymentPolicy::class, BankAccount::class => BankAccountPolicy::class,
        ] as $model => $policy) {
            Gate::policy($model, $policy);
        }
        // M05-04: an approved disposal posts its journal synchronously, inside the disposal's transaction.
        Event::listen(AssetDisposed::class, PostAssetDisposal::class);
        Event::listen(AssetDepreciationDue::class, PostAssetDepreciation::class);
        Gate::define('set-tax-treatment', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::Edit, 'tax_review'));
        Gate::define('view-finance-reports', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::View, 'finance_reports'));
        Gate::define('export-finance-reports', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::Export, 'finance_reports'));

        // Export Centre (M11): every finance report, gated by finance_reports:export.
        $catalog = $this->app->make(ReportCatalog::class);
        $asOf = ['trial-balance', 'receivables-ageing', 'payables-ageing'];
        foreach (ReportController::REPORTS as $key => $name) {
            $catalog->register(new ReportDefinition('finance.'.$key, $name, 'Finance', 'finance_reports',
                fn (array $f): array => app(FinanceReports::class)->run($key, $f),
                match (true) {
                    in_array($key, $asOf, true) => ['as_of'], $key === 'claims-outstanding' => [], $key === 'profit-and-loss' => ['from', 'to', 'basis'], $key === 'general-ledger' => ['from', 'to', 'account_id'], default => ['from', 'to']
                }));
        }
        $catalog->register(new ReportDefinition('finance.reconciliation-status', 'Bank reconciliation status', 'Finance', 'finance_reports',
            fn (array $f): array => app(FinanceReports::class)->reconciliationSummary(Carbon::parse($f['as_of'] ?? today()->toDateString())), ['as_of']));

        // Daily approval reminders (G-05): claims, bills and payments waiting at their current level.
        $this->app->make(PendingApprovalRegistry::class)->register('finance', function (): array {
            $approvals = app(ApprovalService::class);
            $items = [];
            foreach (Claim::query()->where('status', 'pending_approval')->limit(200)->get() as $c) {
                $items[] = ['key' => "claim:{$c->id}:L{$c->current_level}", 'title' => "Claim {$c->claim_no} (RM {$c->total_amount->getAmount()})", 'url' => route('finance.claims.show', $c->id),
                    'roles' => $approvals->rolesFor('claim', $c->total_amount, $c->current_level), 'preparer_id' => $c->staff_user_id];
            }
            foreach (Bill::query()->where('status', 'pending_approval')->limit(200)->get() as $b) {
                $items[] = ['key' => "bill:{$b->id}:L{$b->current_level}", 'title' => "Bill {$b->bill_no} (RM {$b->total_amount->getAmount()})", 'url' => route('finance.bills.show', $b->id),
                    'roles' => $approvals->rolesFor('bill', $b->total_amount, $b->current_level), 'preparer_id' => $b->created_by];
            }
            foreach (Payment::query()->where('status', 'pending_approval')->limit(200)->get() as $p) {
                $items[] = ['key' => "payment:{$p->id}:L{$p->current_level}", 'title' => "Payment {$p->payment_no} to {$p->payee_name} (RM {$p->amount->getAmount()})", 'url' => route('finance.payments.show', $p->id),
                    'roles' => $approvals->rolesFor('payment', $p->amount, $p->current_level), 'preparer_id' => $p->created_by];
            }

            return $items;
        });

        // Evidence can be attached to finance records through the Document Centre (M01-09, M06-01).
        $registry = $this->app->make(DocumentLinkRegistry::class);
        $registry->register(new LinkableEntityType('claim', Claim::class, 'Claim', ['receipt', 'evidence', 'attachment', 'other'], labelFor: fn (Claim $c): string => $c->claim_no));
        $registry->register(new LinkableEntityType('claim_line', ClaimLine::class, 'Claim line', ['receipt', 'evidence', 'attachment'],
            evidenceSubjects: fn (string $condition): array => $condition === 'claim_type.receipt_required'
                ? ClaimLine::query()->join('claims', 'claims.id', '=', 'claim_lines.claim_id')->join('claim_types', 'claim_types.id', '=', 'claim_lines.claim_type_id')
                    ->where('claim_types.receipt_required', true)->whereNotIn('claims.status', ['draft', 'cancelled'])
                    ->selectRaw("claim_lines.id, claims.claim_no || ' L' || claim_lines.line_no || ' ' || claim_lines.description AS label")->pluck('label', 'claim_lines.id')->all()
                : [],
            labelFor: fn (ClaimLine $l): string => $l->claim->claim_no.' L'.$l->line_no.' '.$l->description));
        $registry->register(new LinkableEntityType('transaction', Transaction::class, 'Income/expense', ['receipt', 'invoice', 'evidence', 'attachment'], labelFor: fn (Transaction $t): string => $t->transaction_no.' '.($t->description ?? '')));
        $registry->register(new LinkableEntityType('bill', Bill::class, 'Vendor bill', ['invoice', 'receipt', 'evidence', 'attachment'], labelFor: fn (Bill $b): string => $b->bill_no.' '.$b->vendor_invoice_no));
        $registry->register(new LinkableEntityType('payment', Payment::class, 'Payment', ['issued_document', 'evidence', 'attachment'], labelFor: fn (Payment $p): string => $p->payment_no));
        $registry->register(new LinkableEntityType('bank_account', BankAccount::class, 'Bank account', ['evidence', 'attachment'], labelFor: fn (BankAccount $b): string => $b->label()));
    }
}
