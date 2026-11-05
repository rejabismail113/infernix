<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Finance\Services\BankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Finance overview: bank balances, what needs attention. Figures come from the same report queries. @see SRS M01 screens */
final class OverviewController
{
    public function __invoke(Request $request, FinanceReports $reports, BankService $bank): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('view-finance-reports') || $user->can('viewAny', Claim::class), 403);
        $isFinance = Gate::forUser($user)->allows('view-finance-reports');

        return Inertia::render('Finance/Overview', [
            'isFinance' => $isFinance,
            'banks' => $isFinance ? BankAccount::query()->with('companyAccount')->get()->map(fn (BankAccount $b): array => ['id' => $b->id, 'label' => $b->label(), 'balance' => (string) $bank->ledgerBalance($b, today()->toDateString())->getAmount()]) : [],
            'counts' => $isFinance ? [
                'claims_pending' => DB::table('claims')->where('status', 'pending_approval')->count(),
                'claims_payable' => DB::table('claims')->where('status', 'finance_payable')->count(),
                'bills_pending' => DB::table('bills')->where('status', 'pending_approval')->count(),
                'bills_unpaid' => DB::table('bills')->whereIn('status', ['approved', 'partially_paid'])->count(),
                'payments_to_approve' => DB::table('payments')->where('status', 'pending_approval')->count(),
                'payments_to_release' => DB::table('payments')->where('status', 'approved')->count(),
                'unmatched_bank' => DB::table('bank_transactions')->where('status', 'unmatched')->count(),
                'tax_review' => DB::table('transactions')->where('status', 'posted')->where('tax_treatment', 'pending_review')->count(),
            ] : [],
            'myClaims' => Claim::query()->where('staff_user_id', $user->id)->latest('id')->limit(5)->get()->map(fn (Claim $c): array => ['id' => $c->id, 'claim_no' => $c->claim_no, 'purpose' => $c->purpose, 'status' => $c->status->value, 'total_amount' => (string) $c->total_amount->getAmount()]),
            'claimsOutstanding' => $isFinance ? $reports->claimsOutstanding()['totals'] : null,
        ]);
    }
}
