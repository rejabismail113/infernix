<?php

declare(strict_types=1);

namespace App\Modules\Finance;

use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
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
        //
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
        Gate::define('set-tax-treatment', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::Edit, 'tax_review'));
        Gate::define('view-finance-reports', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::View, 'finance_reports'));
        Gate::define('export-finance-reports', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::Export, 'finance_reports'));

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
