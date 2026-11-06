<?php

declare(strict_types=1);

use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Services\DocumentService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\BillService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Finance\Services\TransactionService;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\HumanResources\Services\StaffService;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\Files;
use Tests\TestCase;

/*
 | Requirement traceability: every test carries ->group('<REQ-ID>') tags (e.g. 'G-01', 'M01-06',
 | 'UAT-05'). docs/TRACEABILITY.md maps IDs to test files. See CLAUDE.md.
 |
 | Feature tests run inside a transaction (RefreshDatabase) on the seeded PostgreSQL database.
 | Concurrency tests need COMMITTED rows visible to forked processes, so they use DatabaseTruncation.
 */

pest()->extend(TestCase::class)->in('Unit', 'Architecture');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');

/**
 * Store a PDF through the real DocumentService (the code path every module uses).
 *
 * @param  array{title?: string, classification?: string, retention_rule?: string, tags?: list<string>}  $meta
 */
function storeDocument(User $owner, array $meta = [], ?LinkTarget $link = null, ?UploadedFile $file = null): Document
{
    $stored = app(DocumentService::class)->attach($file ?? Files::pdf(), $owner, $meta, $link);

    return Document::query()->findOrFail($stored->documentId);
}

/** The seeded company bank account (finance view). */
function bank(): BankAccount
{
    return BankAccount::query()->with(['companyAccount', 'glAccount'])->firstOrFail();
}

function account(string $systemKeyOrCode): ChartAccount
{
    return ChartAccount::query()->where('system_key', $systemKeyOrCode)->orWhere('code', $systemKeyOrCode)->firstOrFail();
}

function categoryId(string $code): int
{
    return (int) DictionaryItem::query()->whereRelation('dictionary', 'code', 'expense_category')->where('code', $code)->value('id');
}

/** Net debit − credit on an account across posted/reversed journals, as a decimal string. */
function balanceOf(string $systemKeyOrCode): string
{
    $id = account($systemKeyOrCode)->id;
    $r = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->where('account_id', $id)->where('journals.status', '!=', 'draft')->selectRaw('COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) AS b')->first();

    return (string) Money::fromDb((string) $r->b)->getAmount();
}

/**
 * Create and post an expense/income register entry through the real service.
 *
 * @param  array<string, mixed>  $overrides
 */
function postedTransaction(array $overrides = [], ?User $actor = null): Transaction
{
    $actor ??= ownerUser();
    $svc = app(TransactionService::class);
    $t = $svc->create(array_merge([
        'direction' => 'outflow', 'payment_source' => 'DIRECT_COMPANY', 'capitalization_class' => 'EXPENSE', 'transaction_date' => today()->toDateString(),
        'bank_account_id' => bank()->id, 'description' => 'Test expense', 'lines' => [['description' => 'Printer paper', 'amount' => '100.00', 'category_id' => categoryId('office')]],
    ], $overrides), $actor);

    return $svc->post($t, $actor);
}

/** Assert the session has a validation error for $key whose message contains $needle. */
function expectSessionErrorContains(string $key, string $needle): void
{
    /** @var ViewErrorBag|null $bag */
    $bag = session('errors');
    expect($bag?->first($key) ?? '')->toContain($needle);
}

/** The seeded Owner (OWN role). */
function ownerUser(): User
{
    return User::query()->where('email', config('infernix.seed_owner.email'))->firstOrFail();
}

/** A fresh user holding the given role codes (roles are seeded). */
function userWithRoles(string ...$codes): User
{
    return User::factory()->withRoles(...$codes)->create()->fresh(['roles']);
}

/**
 * Act as a user with MFA + recent-auth session gates already satisfied, so tests exercise
 * authorization rather than the login ceremony (which has its own tests).
 */
function actingAsSatisfied(User $user): TestCase
{
    /** @var TestCase $tc */
    $tc = test();
    if ($user->mfaRequired() && ! $user->mfaEnabled()) {
        // Roles that require MFA (OWN/FIN) are enrolled directly; MfaTest covers the enrolment ceremony itself.
        User::withoutEvents(fn () => $user->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save());
    }

    return $tc->actingAs($user)->withSession([
        EnsureMfa::SESSION_KEY => now()->toIso8601String(),
        RequireRecentAuth::SESSION_KEY => now()->toIso8601String(),
        RequireRecentAuth::MFA_SESSION_KEY => now()->toIso8601String(),
    ]);
}

function makeClient(array $overrides = []): Client
{
    return Client::query()->create(array_merge([
        'company_id' => Company::current()->id, 'name' => 'Acme Training Sdn Bhd', 'registration_no' => '202601234567',
        'email' => 'ap@acme.example', 'address' => ['line1' => 'Jalan Ampang 1', 'city' => 'Kuala Lumpur', 'postcode' => '50450'], 'payment_terms_days' => 30,
        'status' => 'active', 'created_by' => ownerUser()->id,
    ], $overrides));
}

/**
 * Draft → approved (by a different FIN) → issued invoice for one line.
 *
 * @param  array<string, mixed>  $extra
 */
function issuedInvoice(string $amount, ?Client $client = null, array $extra = []): Invoice
{
    $svc = app(CommercialDocumentService::class);
    $maker = userWithRoles('FIN');
    $checker = userWithRoles('FIN');
    $client ??= makeClient();
    $doc = $svc->createDraft(DocumentType::Invoice, array_merge(['client_id' => $client->id, 'title' => 'Leadership workshop',
        'lines' => [['description' => 'Workshop delivery', 'quantity' => '1', 'unit' => 'lot', 'unit_price' => $amount]]], $extra), $maker);
    $svc->submit($doc, $maker);
    approveFully($doc, $checker);
    /** @var Invoice $issued */
    $issued = $svc->issue($doc->fresh(), $checker);

    return $issued;
}

/** Approve every level of a pending commercial document: level 1 by $first (or a fresh FIN), level 2+ by the Owner. */
function approveFully(CommercialDocument $doc, ?User $first = null): void
{
    $svc = app(CommercialDocumentService::class);
    $fresh = fn () => $doc::query()->findOrFail($doc->id);
    while ($fresh()->status === DocumentStatus::PendingApproval) {
        $svc->approve($fresh(), $fresh()->current_level === 1 ? ($first ?? userWithRoles('FIN')) : ownerUser());
    }
}

/** Staff profile with a rate agreement v1 (default: part-time contractor at RM 50/hour from 2026-01-01). */
function makeStaff(?User $user = null, string $classification = 'contractor', string $rate = '50.00', string $rateType = 'hourly'): StaffProfile
{
    $hr = ownerUser();
    $svc = app(StaffService::class);
    $staff = $svc->create(['user_id' => $user?->id, 'engagement_type' => $classification === 'employee' ? 'employee' : 'part_time', 'legal_name' => 'Nur Aisyah binti Ahmad '.Str::random(4),
        'id_type' => 'nric', 'id_number' => '900101-14-5678', 'email' => 'aisyah@example.com', 'position' => 'Trainer', 'joined_on' => '2026-01-01', 'classification' => $classification], $hr);
    $svc->addEngagement($staff, ['rate_type' => $rateType, 'rate_amount' => $rate, 'effective_from' => '2026-01-01', 'title' => 'Agreement v1'], $hr);

    return $staff->fresh();
}

/** Project with an optional PM user and team members (staff profiles) assigned from 2026-01-01. */
function makeProject(?User $pm = null, array $members = []): Project
{
    $svc = app(ProjectService::class);
    $p = $svc->create(['client_id' => makeClient()->id, 'name' => 'Programme '.Str::random(4), 'engagement_type' => 'training', 'contract_value' => '20000.00', 'pm_user_id' => $pm?->id, 'starts_on' => '2026-01-01'], ownerUser());
    foreach ($members as $staff) {
        $svc->addMember($p, ['staff_profile_id' => $staff->id, 'role' => 'member', 'from_date' => '2026-01-01'], ownerUser());
    }

    return $p->fresh();
}

/** A vendor for P3 tests (M10). */
function makeVendor(string $name = 'Tech Supplies Sdn Bhd'): Vendor
{
    return Vendor::query()->create(['company_id' => ownerUser()->company_id, 'name' => $name.' '.Str::random(3), 'status' => 'active']);
}

/**
 * Draft → submitted (by a FIN maker) → approved (by the Owner) vendor bill.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $extra
 */
function approvedVendorBill(Vendor $vendor, array $lines, string $invoiceNo, array $extra = []): Bill
{
    $fin = userWithRoles('FIN');
    $svc = app(BillService::class);
    $bill = $svc->create(['vendor_id' => $vendor->id, 'vendor_invoice_no' => $invoiceNo, 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(), 'lines' => $lines] + $extra, $fin);
    $svc->submit($bill, $fin);

    return $svc->approve($bill->fresh(), ownerUser())->fresh();
}

/** Pay a bill in full through the real maker-checker payment flow. */
function payVendorBill(Bill $bill, string $amount): Payment
{
    $maker = userWithRoles('FIN');
    $svc = app(PaymentService::class);
    $p = $svc->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'payee_type' => 'vendor', 'payee_id' => $bill->vendor_id, 'payee_name' => 'Vendor',
        'allocations' => [['target_type' => 'bill', 'target_id' => $bill->id, 'amount' => $amount]]], $maker);
    $svc->submit($p, $maker);
    $svc->approve($p->fresh(), ownerUser());

    return $svc->post($p->fresh(), $maker);
}

/** @return array{transactions: int, journals: int, bills: int, payments: int, claims: int} money-row counts (rule 12 checks) */
function moneyRowCounts(): array
{
    return ['transactions' => DB::table('transactions')->count(), 'journals' => DB::table('journals')->count(), 'bills' => DB::table('bills')->count(),
        'payments' => DB::table('payments')->count(), 'claims' => DB::table('claims')->count()];
}
